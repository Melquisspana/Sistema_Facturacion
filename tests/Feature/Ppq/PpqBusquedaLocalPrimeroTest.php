<?php

namespace Tests\Feature\Ppq;

use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\GmailCuenta;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Models\User;
use App\Services\Ppq\AlbaranParser;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use App\Services\Ppq\PpqGmailService;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FASE 1 DE PPQ — LA BASE LOCAL ES LA FUENTE, GMAIL ES LA EXCEPCIÓN.
 *
 * EL PROBLEMA QUE FIJA. La búsqueda usaba Gmail como fuente principal y solo miraba la
 * base local cuando Gmail NO estaba disponible (`(! $gmailDisponible && $hayFiltros)`).
 * Buscar un CCF que este mismo sistema emitió —con su sello, su cliente, su sala y su
 * albarán ya guardados— salía igual a la red: listar correos, bajar el adjunto,
 * parsear el JSON y encima buscar el albarán en el label de Calleja. Seis peticiones a
 * Gmail para responder algo que la base ya sabía.
 *
 * LAS REGLAS QUE SE EXIGEN ACÁ:
 *   - se consulta SIEMPRE la base local primero;
 *   - un documento local de producción, aceptado realmente por Hacienda y no archivado
 *     cierra la búsqueda: CERO llamadas a Gmail;
 *   - Gmail sigue funcionando como fallback para los históricos de Conta/P001;
 *   - si el mismo documento aparece en ambas fuentes, gana el local y no se duplica;
 *   - el albarán se busca primero en `ppq_albaranes`;
 *   - Gmail desconectado no rompe ni degrada lo local;
 *   - el costo en consultas no crece con la cantidad de resultados.
 *
 * Toda la suite corre con `Http::preventStrayRequests()` (heredado de {@see TestCase}),
 * así que ninguna de estas pruebas puede salir a la red de verdad ni por accidente.
 */
class PpqBusquedaLocalPrimeroTest extends TestCase
{
    use RefreshDatabase;

    /** Contenedor con el que abre cada ficha de resultado: sirve para contarlas. */
    private const MARCA_FICHA = 'class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden"';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contabilidad', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        PpqSala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Redundante con el candado de la suite, pero explícito: estas pruebas afirman
        // cuántas veces se habla con Gmail, así que una petición fugada debe reventar.
        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------------ utilidades

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    /**
     * CCF local ACEPTADO REALMENTE por Hacienda en PRODUCCIÓN: el documento que, según
     * la regla de la Fase 1, resuelve la ficha sin Gmail.
     */
    private function ccfResolutivo(string $numeroControl, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => $numeroControl,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.substr(md5($numeroControl), 0, 8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    /** Cuenta de Gmail "conectada" para que `disponible()` diga que sí. */
    private function conectarGmail(): void
    {
        config([
            'ppq.gmail.enabled' => true,
            'ppq.gmail.client_id' => 'id-de-prueba',
            'ppq.gmail.client_secret' => 'secreto-de-prueba',
            'ppq.gmail.redirect_uri' => 'https://ejemplo.test/callback',
        ]);

        GmailCuenta::create([
            'email' => 'prueba@ejemplo.test',
            'access_token' => json_encode(['access_token' => 'x', 'expires_in' => 3600]),
            'refresh_token' => 'refresh-de-prueba',
            'expires_at' => now()->addHour(),
            'scopes' => 'https://www.googleapis.com/auth/gmail.readonly',
        ]);
    }

    /**
     * Doble de GmailClient que CUENTA cada llamada. Cualquier consulta a Gmail queda
     * registrada, así que "cero llamadas" se puede afirmar y no suponer.
     */
    private function gmailContador(array $correos = []): GmailClient
    {
        return new class($correos) extends GmailClient
        {
            /** @var array<int, string> */
            public array $llamadas = [];

            /** @param array<int, array{id: string, json: array<string, mixed>}> $correos */
            public function __construct(public array $correos = []) {}

            public function disponible(): bool
            {
                return true; // conectado: si igual no se lo llama, es por la regla nueva
            }

            public function configurado(): bool
            {
                return true;
            }

            public function buscarEnviadosDetallado(string $numero, int $limite = 15): array
            {
                $this->llamadas[] = 'buscarEnviadosDetallado:'.$numero;

                return [
                    'variante' => $numero,
                    'query' => 'in:sent '.$numero,
                    'resultados' => array_map(
                        fn ($c) => ['id' => $c['id'], 'snippet' => '', 'asunto' => $c['id'], 'fecha' => ''],
                        $this->correos,
                    ),
                    'intentos' => [],
                ];
            }

            public function adjuntos(string $messageId): array
            {
                $this->llamadas[] = 'adjuntos:'.$messageId;

                foreach ($this->correos as $c) {
                    if ($c['id'] === $messageId) {
                        return [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode($c['json'])]];
                    }
                }

                return [];
            }

            public function buscarAlbaranes(string $filtroTexto = '', int $limite = 20): array
            {
                $this->llamadas[] = 'buscarAlbaranes:'.$filtroTexto;

                return [];
            }

            public function buscarAlbaranesPorFecha(string $fecha, int $limite = 40): array
            {
                $this->llamadas[] = 'buscarAlbaranesPorFecha:'.$fecha;

                return [];
            }
        };
    }

    /** Registra el doble en el contenedor y devuelve la instancia para inspeccionarla. */
    private function usarGmail(GmailClient $cliente): GmailClient
    {
        $this->app->instance(GmailClient::class, $cliente);
        $this->app->bind(PpqGmailService::class, fn () => new PpqGmailService(
            $cliente,
            new DteCorreoParser,
            new JsonAdjuntoDecoder,
            new AlbaranParser,
        ));

        return $cliente;
    }

    /** JSON de un DTE tal como llega adjunto en el correo enviado. */
    private function jsonDte(string $control, string $codigo, string $tipo = '03', ?string $oc = '260600232002345'): array
    {
        return [
            'identificacion' => [
                'numeroControl' => $control,
                'codigoGeneracion' => $codigo,
                'tipoDte' => $tipo,
                'fecEmi' => '2026-07-07',
            ],
            'resumen' => ['totalPagar' => 113.58],
            'receptor' => ['nombreComercial' => 'Selectos Prueba'],
            'apendice' => $oc ? [['campo' => 'ordenCompra', 'valor' => $oc]] : [],
        ];
    }

    // ------------------------------------------------------------------ 1. local sin Gmail

    public function test_dte_local_aceptado_se_encuentra_sin_ninguna_llamada_a_gmail(): void
    {
        $this->conectarGmail();
        $ccf = $this->ccfResolutivo('DTE-03-M001P002-000000000000986');
        $gmail = $this->usarGmail($this->gmailContador([
            ['id' => 'correo-986', 'json' => $this->jsonDte('DTE-03-M001P002-000000000000986', 'GEN-986')],
        ]));

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0986']));

        $resp->assertOk();
        $resp->assertSee($ccf->numero_control, false);
        // LA AFIRMACIÓN CENTRAL DE LA FASE 1: la red no se tocó.
        $this->assertSame([], $gmail->llamadas, 'Un DTE local aceptado NO debe disparar ninguna consulta a Gmail.');
        $resp->assertSee('No hizo falta consultar Gmail', false);
    }

    public function test_el_dte_local_resuelve_toda_la_ficha_cliente_sala_y_albaran(): void
    {
        $this->conectarGmail();
        $sucursal = ClienteSucursal::create([
            'cliente_id' => $this->calleja()->id,
            'nombre' => 'Súper Selectos Ilobasco',
            'codigo' => '0232',
        ]);
        $ccf = $this->ccfResolutivo('DTE-03-M001P002-000000000000987', ['cliente_sucursal_id' => $sucursal->id]);
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/7788',
            'numero_orden_compra' => $ccf->numero_orden_compra,
            'monto_albaran' => 113.58,
            'fecha_albaran' => now(),
            'origen' => 'gmail',
            'gmail_message_id' => 'msg-albaran',
        ]);
        $gmail = $this->usarGmail($this->gmailContador());

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0987']));

        $resp->assertOk();
        $resp->assertSee('Súper Selectos Ilobasco', false);   // sala, vía la sucursal local
        $resp->assertSee('AC01/0232/00/7788', false);         // albarán, vía ppq_albaranes
        $resp->assertSee('Albarán sincronizado', false);      // y se dice de dónde salió
        $this->assertSame([], $gmail->llamadas);
    }

    // ------------------------------------------------------------------ 2. Gmail como fallback

    public function test_sin_coincidencia_local_gmail_resuelve_el_historico_de_conta(): void
    {
        $this->conectarGmail();
        // Nada local: este P001 lo emitió ContaPortable, no este sistema.
        $gmail = $this->usarGmail($this->gmailContador([
            ['id' => 'correo-p001', 'json' => $this->jsonDte('DTE-03-M001P001-000000000000404', 'GEN-P001')],
        ]));

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0404']));

        $resp->assertOk();
        $resp->assertSee('DTE-03-M001P001-000000000000404', false);
        $resp->assertSee('Histórico Conta', false);           // la interfaz nombra la fuente
        $this->assertContains('buscarEnviadosDetallado:0404', $gmail->llamadas);
    }

    public function test_un_documento_local_no_resolutivo_no_cierra_la_busqueda(): void
    {
        $this->conectarGmail();
        // Existe local, pero es de PRUEBAS y sin sello real: no prueba nada ante Hacienda.
        Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'borrador',
            'ambiente' => '00',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => 'DTE-03-M001P002-000000000000505',
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 50.0,
        ]);
        $gmail = $this->usarGmail($this->gmailContador());

        $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0505']))->assertOk();

        // Sigue consultándose Gmail: puede haber un P001 histórico que sí valga.
        $this->assertContains('buscarEnviadosDetallado:0505', $gmail->llamadas);
    }

    // ------------------------------------------------------------------ 3. local gana / sin duplicados

    public function test_un_local_no_elegible_cede_ante_la_evidencia_de_gmail_y_no_duplica(): void
    {
        // EL CASO REAL DE CALLEJA, medido en la base de producción: cinco CCF de la serie
        // P001 que emitió ContaPortable. Este sistema los tiene registrados en estado
        // «generado» —nunca los transmitió, por eso no tienen sello— mientras que el
        // correo de Conta sí trae el sello real.
        //
        // Si ganara el local, la única tarjeta visible sería la local, marcada «No
        // disponible para PPQ» y sin botones: el documento quedaría INALCANZABLE, cuando
        // hoy se cobra sin problema por la vía de Gmail. Por eso acá gana el correo.
        $this->conectarGmail();
        $local = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'generado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => 'DTE-03-M001P001-000000000000606',
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
        $this->usarGmail($this->gmailContador([
            ['id' => 'correo-606', 'json' => $this->jsonDte('DTE-03-M001P001-000000000000606', 'GEN-606')],
        ]));
        PpqLote::create(['referencia' => 'PPQ', 'fecha' => now(), 'estado' => 'borrador']);

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0606']));

        $resp->assertOk();
        $html = $resp->getContent();

        // UNA sola ficha: no se muestran la local bloqueada y la de Gmail a la vez.
        $this->assertSame(1, substr_count($html, self::MARCA_FICHA), 'El mismo documento no puede aparecer dos veces.');

        // Y la que quedó es la de GMAIL: se puede agregar, que es lo que importa.
        $this->assertStringContainsString('correo-606', $html, 'Debe verse la ficha de Gmail, que es la que trae el sello de Conta.');
        $resp->assertSee('Histórico Conta', false);
        $resp->assertSee('Agregar sin albarán', false);
        $resp->assertDontSee('No disponible para PPQ', false);

        // El DTE local sigue existiendo: no se borró ni se tocó, solo no se dibuja.
        $this->assertDatabaseHas('dtes', ['id' => $local->id, 'estado' => 'generado']);
    }

    public function test_un_local_no_elegible_sin_correo_se_muestra_bloqueado(): void
    {
        // La contracara: si Gmail NO tiene el documento, la ficha local sigue viéndose
        // —existe, y ocultarla sería mentir— pero marcada y sin ninguna vía de alta.
        $this->conectarGmail();
        $local = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'generado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => 'DTE-03-M001P002-000000000000607',
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
        $this->usarGmail($this->gmailContador()); // buzón vacío
        PpqLote::create(['referencia' => 'PPQ', 'fecha' => now(), 'estado' => 'borrador']);

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0607']));

        $resp->assertOk();
        $resp->assertSee($local->numero_control, false);
        $resp->assertSee('No disponible para PPQ', false);
        $resp->assertDontSee('Agregar sin albarán', false);
    }

    public function test_p002_gana_sobre_p001_cuando_comparten_correlativo(): void
    {
        $this->conectarGmail();
        $p001 = $this->ccfResolutivo('DTE-03-M001P001-000000000000700');
        $p002 = $this->ccfResolutivo('DTE-03-M001P002-000000000000700');
        $this->usarGmail($this->gmailContador());

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0700']));

        $resp->assertOk();
        $resp->assertSee($p002->numero_control, false);
        $resp->assertDontSee($p001->numero_control, false);
    }

    // ------------------------------------------------------------------ 4. albaranes

    public function test_el_albaran_local_evita_bajar_el_correo_del_albaran(): void
    {
        $this->conectarGmail();
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/9090',
            'numero_orden_compra' => '260600232002345',
            'monto_albaran' => 113.58,
            'fecha_albaran' => now(),
            'origen' => 'gmail',
            'gmail_message_id' => 'msg-ya-sincronizado',
        ]);
        // Documento SOLO en Gmail (histórico P001): Gmail sí se consulta para el DTE…
        $gmail = $this->usarGmail($this->gmailContador([
            ['id' => 'correo-p001', 'json' => $this->jsonDte('DTE-03-M001P001-000000000000808', 'GEN-808')],
        ]));

        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0808']));

        $resp->assertOk();
        $resp->assertSee('AC01/0232/00/9090', false);
        $resp->assertSee('Albarán sincronizado', false);
        // …pero el ALBARÁN ya estaba sincronizado: no se vuelve a buscar ni a bajar.
        $this->assertNotContains('buscarAlbaranes:260600232002345', $gmail->llamadas);
        $this->assertSame(
            [],
            array_filter($gmail->llamadas, fn ($l) => str_starts_with($l, 'buscarAlbaranesPorFecha')),
            'Un albarán ya sincronizado no debe disparar el barrido por fecha en Gmail.',
        );
    }

    public function test_sin_albaran_local_se_cae_a_gmail_y_se_identifica_como_excepcional(): void
    {
        $this->conectarGmail();
        $gmail = $this->usarGmail($this->gmailContador([
            ['id' => 'correo-p001', 'json' => $this->jsonDte('DTE-03-M001P001-000000000000909', 'GEN-909')],
        ]));

        $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '0909']))->assertOk();

        // Sin fila en `ppq_albaranes`, el fallback a Gmail sigue existiendo.
        $this->assertContains('buscarAlbaranes:260600232002345', $gmail->llamadas);
    }

    public function test_el_albaran_se_busca_una_sola_vez_por_documento_real_no_por_correo(): void
    {
        $this->conectarGmail();
        // El mismo CCF reenviado tres veces + un DTE ajeno que solo menciona el número.
        $gmail = $this->usarGmail($this->gmailContador([
            ['id' => 'c1', 'json' => $this->jsonDte('DTE-03-M001P001-000000000001010', 'GEN-A')],
            ['id' => 'c2', 'json' => $this->jsonDte('DTE-03-M001P001-000000000001010', 'GEN-A')],
            ['id' => 'c3', 'json' => $this->jsonDte('DTE-03-M001P001-000000000001010', 'GEN-A')],
            ['id' => 'c4', 'json' => $this->jsonDte('DTE-03-M001P001-000000000009999', 'GEN-B')],
        ]));

        $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '1010']))->assertOk();

        // Antes se buscaba el albarán DENTRO del bucle de correos: cuatro búsquedas para
        // un solo documento. Ahora se resuelve después del filtro y el dedup.
        $busquedasAlbaran = array_filter($gmail->llamadas, fn ($l) => str_starts_with($l, 'buscarAlbaranes:'));
        $this->assertCount(1, $busquedasAlbaran);
    }

    // ------------------------------------------------------------------ 5. Gmail caído

    public function test_gmail_desconectado_no_afecta_a_los_documentos_locales(): void
    {
        $ccf = $this->ccfResolutivo('DTE-03-M001P002-000000000001111');
        // Gmail ni configurado ni conectado: el peor caso.
        $resp = $this->actingAs($this->usuario())->get(route('ppq.index', ['q' => '1111']));

        $resp->assertOk();
        $resp->assertSee($ccf->numero_control, false);
        // Y NO se alarma al usuario: PPQ no está caído, solo faltan los históricos.
        $resp->assertDontSee('Reconectar Gmail', false);
        $resp->assertSee('No hizo falta consultar Gmail', false);
    }

    public function test_token_revocado_avisa_pero_acota_el_alcance_a_los_historicos(): void
    {
        $this->conectarGmail();
        $muerto = new class extends GmailClient
        {
            public function disponible(): bool
            {
                return true;
            }

            public function buscarEnviadosDetallado(string $numero, int $limite = 15): array
            {
                throw new GmailDesconectadoException('La conexión con Gmail expiró o fue revocada. Reconectá la cuenta.');
            }
        };
        $this->usarGmail($muerto);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('ppq.index', ['q' => '2222']));

        $resp->assertOk(); // no revienta
        $resp->assertSee('La conexión con Gmail expiró o fue revocada', false);
        $resp->assertSee('históricos de Conta', false); // el aviso dice QUÉ es lo que falta
    }

    // ------------------------------------------------------------------ 6. NC y permisos

    public function test_la_nc_conserva_sus_reglas_de_tipo_y_no_se_le_busca_albaran_automatico(): void
    {
        $this->conectarGmail();
        $ccf = $this->ccfResolutivo('DTE-03-M001P002-000000000003030');
        $nc = $this->ccfResolutivo('DTE-05-M001P002-000000000003030', ['tipo_dte' => '05', 'total_pagar' => 20.0]);
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/3030',
            'numero_orden_compra' => '260600232002345',
            'monto_albaran' => 113.58,
            'origen' => 'gmail',
        ]);
        $usuario = $this->usuario();

        // Modo CCF: solo el CCF, con su albarán.
        $this->actingAs($usuario)->get(route('ppq.index', ['q' => '3030']))
            ->assertOk()
            ->assertSee($ccf->numero_control, false)
            ->assertDontSee($nc->numero_control, false);

        // Modo NC: solo la NC, y su albarán sigue siendo captura MANUAL.
        $this->actingAs($usuario)->get(route('ppq.index', ['q' => '3030', 'tipo' => '05']))
            ->assertOk()
            ->assertSee($nc->numero_control, false)
            ->assertDontSee($ccf->numero_control, false)
            ->assertSee('Captura manual', false);
    }

    public function test_los_permisos_de_ppq_siguen_rigiendo_la_busqueda(): void
    {
        $this->ccfResolutivo('DTE-03-M001P002-000000000004040');

        // Sin permiso ppq.ver no se entra, aunque todo sea local.
        $this->actingAs(User::factory()->create())
            ->get(route('ppq.index', ['q' => '4040']))
            ->assertForbidden();

        // Solo lectura (jefatura) entra pero no puede agregar al lote.
        PpqLote::create(['referencia' => 'PPQ', 'fecha' => now(), 'estado' => 'borrador']);
        $this->actingAs($this->usuario('jefatura'))
            ->get(route('ppq.index', ['q' => '4040']))
            ->assertOk()
            ->assertDontSee('Agregar al PPQ', false);
    }

    // ------------------------------------------------------------------ 7. costo estable

    public function test_el_costo_en_consultas_no_crece_con_la_cantidad_de_resultados(): void
    {
        $this->conectarGmail();
        $this->usarGmail($this->gmailContador());
        $usuario = $this->usuario();

        // Un documento con su albarán.
        $this->ccfResolutivo('DTE-03-M001P002-000000000005001', ['numero_orden_compra' => '260600232005001']);
        PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/5001', 'numero_orden_compra' => '260600232005001', 'monto_albaran' => 1, 'origen' => 'gmail']);

        $peticion = fn () => $this->actingAs($usuario)->get(route('ppq.index', ['oc' => '2606002320']));

        $conUno = $this->contarQueries($peticion);

        // Nueve documentos más, cada uno con su albarán y su sucursal.
        foreach (range(5002, 5010) as $n) {
            $oc = '26060023200'.$n;
            $this->ccfResolutivo('DTE-03-M001P002-00000000000'.$n, ['numero_orden_compra' => $oc]);
            PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/'.$n, 'numero_orden_compra' => $oc, 'monto_albaran' => 1, 'origen' => 'gmail']);
        }

        $conDiez = $this->contarQueries($peticion);

        // Diez veces más documentos NO puede costar diez veces más consultas: los
        // albaranes, los lotes y los duplicados se resuelven en bloque. Si esto crece,
        // volvió un N+1.
        $this->assertSame(
            $conUno,
            $conDiez,
            "Consultas con 1 resultado: {$conUno}; con 10: {$conDiez}. El costo debe ser constante.",
        );
    }

    /**
     * Cuenta las consultas SQL que dispara una petición.
     *
     * Antes de medir hace una petición de CALENTAMIENTO y la descarta. No es un truco
     * para que el número dé lindo: varios cachés del proceso (permisos de Spatie, el
     * mapa de salas, la configuración) se llenan en la primera petición de un test y
     * no se vuelven a llenar. Sin el calentamiento, la primera medición contaría esos
     * cachés y la segunda no, y la comparación hablaría del orden de las mediciones en
     * vez de hablar del costo por resultado —que es lo que esta prueba vigila—.
     */
    private function contarQueries(callable $accion): int
    {
        $accion()->assertOk(); // calentamiento, se descarta

        $total = 0;
        $cancelar = false;
        DB::listen(function () use (&$total, &$cancelar) {
            if (! $cancelar) {
                $total++;
            }
        });

        $accion()->assertOk();
        $cancelar = true; // este oyente no debe contar las mediciones siguientes

        return $total;
    }
}
