<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\GmailCuenta;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Models\User;
use App\Services\Ppq\PpqBusquedaService;
use App\Support\PpqElegibilidad;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CANDADO FISCAL DE PPQ — QUÉ DOCUMENTO LOCAL PUEDE ENTRAR A UN LOTE.
 *
 * EL RIESGO QUE CIERRA. Un lote PPQ es un cobro real contra Calleja: termina en un
 * Excel que se le manda al cliente. Si entrara un borrador, un documento del ambiente
 * de pruebas, uno rechazado o uno con sello MOCK, se le estaría cobrando algo que ante
 * Hacienda no existe. Y el `dte_id` viaja en un campo oculto de un POST, así que
 * esconder el botón no alcanza: la comprobación tiene que estar en el servidor.
 *
 * LAS REGLAS QUE SE EXIGEN ACÁ:
 *   - el documento no elegible SE MUESTRA (existe, y ocultarlo sería mentir) con el
 *     cartel «No disponible para PPQ» y la razón concreta;
 *   - pero NO se dibuja ninguna vía para agregarlo;
 *   - y el backend lo rechaza igual aunque el POST venga manipulado, sin crear el item;
 *   - un CCF de producción aceptado realmente por Hacienda SÍ se agrega, como siempre;
 *   - los históricos que llegan por GMAIL no pasan por este candado: no tienen DTE
 *     local que evaluar y aplicárselo los bloquearía a todos.
 *
 * La regla vive en un solo lugar ({@see PpqElegibilidad}); búsqueda, vista
 * y controlador la consultan. Estas pruebas verifican los tres puntos de aplicación.
 */
class PpqElegibilidadItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        PpqSala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
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

    /** Lote en borrador: sin uno abierto la ficha no dibujaría botones de todos modos. */
    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'PPQ candado', 'fecha' => now(), 'estado' => 'borrador']);
    }

    /**
     * Correlativo del próximo documento. Va de 1 en 1 y se queda por debajo de 10000 a
     * propósito: así el número que se escribe al buscar (4 dígitos) ES el correlativo
     * entero, no sus últimas cifras. La búsqueda exige coincidencia EXACTA contra el
     * número de control —para que escribir `0340` no arrastre el `0003401` de otro
     * documento—, así que un correlativo al azar de seis cifras no se encontraría
     * buscando sus últimos cuatro, y la prueba fallaría por su propio andamiaje.
     */
    private int $proximoCorrelativo = 1;

    /**
     * Documento local con los atributos que se quieran. Por defecto, el caso BUENO:
     * CCF de producción aceptado realmente por Hacienda.
     */
    private function documento(array $extra = []): Dte
    {
        $correlativo = $this->proximoCorrelativo++;
        $tipo = $extra['tipo_dte'] ?? '03';

        return Dte::create($extra + [
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $correlativo, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    /** El correlativo tal como lo escribe el usuario: cuatro dígitos con su relleno. */
    private function correlativo(Dte $dte): string
    {
        $secuencia = substr((string) $dte->numero_control, strrpos((string) $dte->numero_control, '-') + 1);

        return str_pad(ltrim($secuencia, '0'), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Afirma que el documento SE VE en la búsqueda, con el cartel y la razón, y que no
     * hay ninguna forma de agregarlo.
     */
    private function assertVisiblePeroBloqueado(Dte $dte, string $razonEsperada): void
    {
        $this->lote();

        $resp = $this->actingAs($this->usuario())
            ->get(route('ppq.index', ['q' => $this->correlativo($dte), 'tipo' => $dte->tipo_dte->value]));

        $resp->assertOk();
        $resp->assertSee($dte->numero_control, false);           // se muestra: existe
        $resp->assertSee('No disponible para PPQ', false);       // y se dice que no sirve
        $resp->assertSee($razonEsperada, false);                 // con la razón concreta
        $resp->assertDontSee('Agregar con albarán', false);
        $resp->assertDontSee('Agregar sin albarán', false);
        $resp->assertDontSee('Agregar NC al PPQ', false);
    }

    /**
     * Afirma que el POST directo se rechaza con un mensaje claro y NO deja item alguno.
     * Es el candado de servidor: acá no hay interfaz que valga.
     */
    private function assertPostBloqueado(Dte $dte): void
    {
        $lote = $this->lote();

        $resp = $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $dte->id, 'sin_albaran' => '1']);

        $resp->assertRedirect();
        $resp->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'no se puede cobrar por PPQ'));

        $this->assertSame(0, PpqItem::count(), 'Un intento bloqueado no debe dejar ningún item.');
        $this->assertDatabaseMissing('ppq_items', ['dte_id' => $dte->id]);
    }

    // ------------------------------------------------------------------ no elegibles

    public function test_borrador_local_se_ve_pero_no_se_puede_agregar(): void
    {
        $borrador = $this->documento([
            'estado' => 'borrador',
            'sello_recepcion' => null,
            'fecha_procesamiento_mh' => null,
        ]);

        $this->assertVisiblePeroBloqueado($borrador, 'Todavía no está aceptado por Hacienda');
        $this->assertPostBloqueado($borrador);
    }

    public function test_documento_de_pruebas_se_ve_pero_no_se_puede_agregar(): void
    {
        $pruebas = $this->documento(['ambiente' => '00']);

        $this->assertVisiblePeroBloqueado($pruebas, 'ambiente de pruebas');
        $this->assertPostBloqueado($pruebas);
    }

    public function test_rechazado_se_ve_pero_no_se_puede_agregar(): void
    {
        // Rechazado SIN archivar: es el que sigue apareciendo en la operación diaria.
        $rechazado = $this->documento([
            'estado' => 'rechazado',
            'sello_recepcion' => null,
            'fecha_procesamiento_mh' => null,
        ]);

        $this->assertVisiblePeroBloqueado($rechazado, 'Hacienda lo rechazó');
        $this->assertPostBloqueado($rechazado);
    }

    public function test_aceptado_con_sello_mock_no_es_agregable(): void
    {
        // El caso traicionero: dice "aceptado" y tiene sello, pero es simulado. Su código
        // de generación no existe en Hacienda, así que cobrarlo sería cobrar humo.
        $mock = $this->documento(['sello_recepcion' => 'MOCK-1234567890']);

        $this->assertVisiblePeroBloqueado($mock, 'No tiene sello de recepción real de Hacienda');
        $this->assertPostBloqueado($mock);
    }

    public function test_aceptado_sin_fecha_de_procesamiento_no_es_agregable(): void
    {
        // Aceptación incompleta: sello real pero sin la huella de procesamiento del MH.
        $incompleto = $this->documento(['fecha_procesamiento_mh' => null]);

        $this->assertVisiblePeroBloqueado($incompleto, 'No tiene sello de recepción real de Hacienda');
        $this->assertPostBloqueado($incompleto);
    }

    public function test_invalidado_no_es_agregable(): void
    {
        $invalidado = $this->documento(['estado' => 'invalidado']);

        $this->assertPostBloqueado($invalidado);
    }

    public function test_archivado_no_es_agregable(): void
    {
        // Un archivado no sale en la búsqueda (`noArchivados()`), pero el POST igual
        // tiene que rechazarlo: el candado no depende de que la pantalla lo esconda.
        $archivado = $this->documento(['estado' => 'aceptado', 'archivado' => true]);

        $this->assertPostBloqueado($archivado);
    }

    public function test_post_manipulado_con_albaran_tampoco_crea_nada(): void
    {
        // Intento más elaborado: además del `dte_id` cambiado, se manda un albarán a mano
        // para ver si algo se persiste por el camino antes del rechazo.
        $pruebas = $this->documento(['ambiente' => '00']);
        $lote = $this->lote();

        $resp = $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'dte_id' => $pruebas->id,
                'numero_albaran' => 'AC01/0232/00/6666',
                'monto_albaran' => '113.58',
                'fecha_albaran' => '2026-07-07',
            ]);

        $resp->assertRedirect();
        $resp->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'no se puede cobrar por PPQ'));
        $this->assertSame(0, PpqItem::count());
        // El candado corre ANTES de tocar `ppq_albaranes`: un intento rechazado no
        // puede dejar sembrado un albarán que después aparezca solo en otra ficha.
        $this->assertDatabaseMissing('ppq_albaranes', ['numero_albaran' => 'AC01/0232/00/6666']);
    }

    // ------------------------------------------------------------------ sí elegibles

    public function test_ccf_de_produccion_aceptado_realmente_si_se_agrega(): void
    {
        $ccf = $this->documento();
        $lote = $this->lote();

        // La ficha ofrece los botones…
        $this->actingAs($this->usuario())
            ->get(route('ppq.index', ['q' => $this->correlativo($ccf)]))
            ->assertOk()
            ->assertDontSee('No disponible para PPQ', false)
            ->assertSee('Agregar sin albarán', false);

        // …y el alta funciona.
        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ppq_items', ['ppq_lote_id' => $lote->id, 'dte_id' => $ccf->id]);
    }

    public function test_nota_de_credito_de_produccion_aceptada_si_se_agrega(): void
    {
        $nc = $this->documento(['tipo_dte' => '05', 'total_pagar' => 20.0]);
        $lote = $this->lote();

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $nc->id, 'sin_albaran' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ppq_items', ['ppq_lote_id' => $lote->id, 'dte_id' => $nc->id]);
    }

    // ------------------------------------------------------------------ Gmail aparte

    public function test_el_historico_de_conta_por_gmail_sigue_siendo_agregable(): void
    {
        // ESTE es el caso que el candado NO debe tocar: un P001 emitido por
        // ContaPortable. No hay DTE local que evaluar —por eso llega por Gmail—, así
        // que se agrega como snapshot por su propio camino, igual que siempre.
        GmailCuenta::create([
            'email' => 'x@y.test',
            'access_token' => json_encode(['access_token' => 'x', 'expires_in' => 3600]),
            'refresh_token' => 'r',
            'expires_at' => now()->addHour(),
            'scopes' => 'readonly',
        ]);
        $lote = $this->lote();

        $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), [
                'origen' => 'gmail',
                'numero_control' => 'DTE-03-M001P001-000000000000404',
                'codigo_generacion' => 'GEN-HISTORICO',
                'sello_recepcion' => '2026SELLOHISTORICO',
                'tipo_dte' => '03',
                'fecha_documento' => '2026-07-07',
                'numero_orden_compra' => '260600232002345',
                'monto_dte' => '113.58',
                'sin_albaran' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ppq_items', [
            'ppq_lote_id' => $lote->id,
            'origen' => 'gmail',
            'numero_control' => 'DTE-03-M001P001-000000000000404',
        ]);
    }

    // ------------------------------------------------------------------ la regla, directa

    public function test_la_regla_de_elegibilidad_es_una_sola_y_da_la_razon_concreta(): void
    {
        $casos = [
            ['extra' => [], 'motivo' => null],
            ['extra' => ['ambiente' => '00'], 'motivo' => 'ambiente de pruebas'],
            ['extra' => ['estado' => 'borrador', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null], 'motivo' => 'Todavía no está aceptado'],
            ['extra' => ['estado' => 'rechazado'], 'motivo' => 'Hacienda lo rechazó'],
            ['extra' => ['estado' => 'invalidado'], 'motivo' => 'Fue invalidado'],
            ['extra' => ['archivado' => true], 'motivo' => 'Está archivado'],
            ['extra' => ['sello_recepcion' => 'MOCK-X'], 'motivo' => 'sello de recepción real'],
        ];

        foreach ($casos as $caso) {
            $dte = $this->documento($caso['extra']);
            $motivo = PpqElegibilidad::motivo($dte);

            if ($caso['motivo'] === null) {
                $this->assertNull($motivo, 'El caso bueno debe ser elegible.');
                $this->assertTrue(PpqElegibilidad::esElegible($dte));

                continue;
            }

            $this->assertNotNull($motivo, 'Debía bloquearse: '.json_encode($caso['extra']));
            $this->assertStringContainsString($caso['motivo'], $motivo);
            $this->assertFalse(PpqElegibilidad::esElegible($dte));
        }
    }

    public function test_la_busqueda_usa_exactamente_la_misma_regla_que_el_candado(): void
    {
        // Si estas dos respuestas se separaran, la búsqueda daría por cerrada una
        // consulta con un documento que el controlador después rechaza.
        foreach ([[], ['ambiente' => '00'], ['estado' => 'borrador'], ['sello_recepcion' => 'MOCK-X']] as $extra) {
            $dte = $this->documento($extra);

            $this->assertSame(
                PpqElegibilidad::esElegible($dte),
                PpqBusquedaService::resuelveSinGmail($dte),
                'La elegibilidad y el cierre de la búsqueda deben coincidir siempre.',
            );
        }
    }
}
