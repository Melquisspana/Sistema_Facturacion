<?php

namespace Tests\Feature\Ppq;

use App\Console\Commands\PpqSincronizarAlbaranesCommand;
use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Configuracion;
use App\Models\PpqAlbaran;
use App\Models\PpqSala;
use App\Services\Ppq\AlbaranParser;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use App\Services\Ppq\PpqGmailService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Sincronización automática de albaranes (`ppq:sincronizar-albaranes`): dry-run por
 * defecto, incremental por fecha del último sincronizado, idempotente ante corridas
 * repetidas y reenvíos, y clasificación de salas desconocidas como excepción sin crear
 * nunca una sucursal.
 */
class PpqSincronizarAlbaranesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Sala::olvidarCache();
        // La marca de progreso vive en `configuraciones` y el modelo cachea en estático:
        // sin esto, una marca escrita por un test se filtraría al siguiente.
        Configuracion::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);

        // La sincronización automática se prueba encendida: estas pruebas ejercitan la
        // LÓGICA de la sincronización (ventana, idempotencia, salas, excepciones), no el
        // interruptor. El interruptor tiene sus propias pruebas en
        // PpqSincronizacionAutomaticaTest, y arranca apagado en toda la suite.
        config(['ppq.albaranes.sincronizacion_automatica' => true]);
    }

    /** Marca de progreso guardada (último día leído completo), o null. */
    private function marca(): ?string
    {
        Configuracion::olvidarCache();

        return Configuracion::get(PpqSincronizarAlbaranesCommand::CLAVE_ULTIMO_DIA);
    }

    private function ponerMarca(string $dia): void
    {
        Configuracion::set(PpqSincronizarAlbaranesCommand::CLAVE_ULTIMO_DIA, $dia);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    private function sala(string $codigo, string $nombre): ClienteSucursal
    {
        $calleja = $this->calleja();
        config(['ppq.cliente_default_id' => $calleja->id]);

        return $calleja->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo]);
    }

    /**
     * Sustituye el PpqGmailService del contenedor por uno que devuelve candidatos ya
     * parseados por día. Aísla la lógica del Command (ventana, idempotencia, excepciones)
     * de la mecánica de Gmail, que se prueba aparte en el test end-to-end de abajo.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $porFecha  YYYY-MM-DD => candidatos
     * @param  array<int, string>  $diasTruncados  días en los que Gmail dejó correos sin devolver
     */
    private function fakeServicio(array $porFecha, bool $disponible = true, array $diasTruncados = []): void
    {
        $this->app->instance(PpqGmailService::class, new class($porFecha, $disponible, $diasTruncados) extends PpqGmailService
        {
            /** @var array<int, string> */
            public array $diasConsultados = [];

            private bool $truncada = false;

            /**
             * @param  array<string, array<int, array<string, mixed>>>  $porFecha
             * @param  array<int, string>  $diasTruncados
             */
            public function __construct(private array $porFecha, private bool $disp, private array $diasTruncados)
            {
                // No se llama a parent::__construct: este doble no toca Gmail.
            }

            public function disponible(): bool
            {
                return $this->disp;
            }

            public function albaranesDeFecha(string $fecha, int $limite = 40): array
            {
                $this->diasConsultados[] = $fecha;
                $this->truncada = in_array($fecha, $this->diasTruncados, true);

                return $this->porFecha[$fecha] ?? [];
            }

            public function ultimaBusquedaTruncada(): bool
            {
                return $this->truncada;
            }
        });
    }

    /**
     * Candidato con la forma que devuelve PpqGmailService::albaranesDeFecha().
     *
     * @return array<string, mixed>
     */
    private function candidato(string $id, string $numero, ?string $oc, ?float $monto, string $fecha): array
    {
        return [
            'gmail_message_id' => $id,
            'numero_albaran' => $numero,
            'orden_compra' => $oc,
            'sala' => $oc ? \App\Support\OrdenCompra::salaDesde($oc) : null,
            'nombre_sala' => null,
            'monto' => $monto,
            'fecha' => $fecha,
            'asunto' => 'Albarán '.$numero,
        ];
    }

    // ------------------------------------------------------------- dry-run

    public function test_sin_aplicar_no_escribe_nada(): void
    {
        $this->sala('0236', 'Súper Selectos Santa Elena');
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14'])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame(0, PpqAlbaran::count());
    }

    public function test_aplicar_persiste_el_albaran_con_su_sala(): void
    {
        $sucursal = $this->sala('0236', 'Súper Selectos Santa Elena');
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->assertExitCode(0);

        $albaran = PpqAlbaran::sole();
        $this->assertSame('AC01/0236/00/6359', $albaran->numero_albaran);
        $this->assertSame('26060236004586', $albaran->numero_orden_compra);
        $this->assertSame('250.75', (string) $albaran->monto_albaran);
        $this->assertSame('2026-07-14', $albaran->fecha_albaran->toDateString());
        $this->assertSame('gmail', $albaran->origen);
        $this->assertSame('msg-1', $albaran->gmail_message_id);
        $this->assertSame($sucursal->id, $albaran->cliente_sucursal_id);
    }

    // --------------------------------------------------------- idempotencia

    public function test_dos_corridas_seguidas_no_duplican(): void
    {
        $this->sala('0236', 'Súper Selectos Santa Elena');
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);

        $opciones = ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true];
        $this->artisan('ppq:sincronizar-albaranes', $opciones)->assertExitCode(0);
        $this->artisan('ppq:sincronizar-albaranes', $opciones)->assertExitCode(0);

        $this->assertSame(1, PpqAlbaran::count());
    }

    public function test_segunda_corrida_omite_los_correos_ya_sincronizados(): void
    {
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);
        $opciones = ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true];

        $this->artisan('ppq:sincronizar-albaranes', $opciones)->assertExitCode(0);
        $this->artisan('ppq:sincronizar-albaranes', $opciones)
            ->expectsOutputToContain('1 ya sincronizados (omitidos)')
            ->assertExitCode(0);
    }

    public function test_un_reenvio_dentro_de_la_misma_corrida_no_duplica(): void
    {
        // El mismo albarán llega dos veces con message id distinto (reenvío): el índice
        // único número+OC lo colapsa en una sola fila.
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
            $this->candidato('msg-2', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->assertExitCode(0);

        $this->assertSame(1, PpqAlbaran::count());
    }

    // ------------------------------------------------------------ incremental

    public function test_sin_desde_arranca_en_el_ultimo_sincronizado_menos_el_solape(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');

        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/1', 'numero_orden_compra' => 'OC-VIEJA',
            'fecha_albaran' => '2026-07-17', 'origen' => 'gmail',
        ]);
        $this->fakeServicio([]);

        // Sin marca de progreso todavía, el respaldo sigue siendo `fecha_albaran`.
        $this->artisan('ppq:sincronizar-albaranes')
            ->expectsOutputToContain('se parte del último albarán sincronizado: 2026-07-17')
            // 17 - 3 de solape = 14, hasta hoy (20) => 7 días.
            ->expectsOutputToContain('Ventana: 2026-07-14 → 2026-07-20 (7 día/s)')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_la_ventana_incremental_solo_consulta_los_dias_del_rango(): void
    {
        Carbon::setTestNow('2026-07-16 09:00:00');
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14'])->assertExitCode(0);

        $this->assertSame(
            ['2026-07-14', '2026-07-15', '2026-07-16'],
            app(PpqGmailService::class)->diasConsultados,
        );

        Carbon::setTestNow();
    }

    public function test_primera_corrida_sin_datos_se_limita_al_solape(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->fakeServicio([]);

        // Sin albaranes previos NO barre Gmail entero: avisa y usa solo el solape.
        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 1])
            ->expectsOutputToContain('No hay albaranes de Gmail todavía')
            ->expectsOutputToContain('Ventana: 2026-07-19 → 2026-07-20')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_dias_acota_la_ventana_hacia_atras(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--dias' => 3])
            ->expectsOutputToContain('Ventana: 2026-07-18 → 2026-07-20 (3 día/s)')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------ excepciones

    public function test_sala_desconocida_se_reporta_como_excepcion_y_no_crea_sucursal(): void
    {
        $sucursalesAntes = ClienteSucursal::count();
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-x', 'AC01/0999/00/7777', '26060999001234', 90.00, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->expectsOutputToContain('EXCEPCIONES: 1 con sala desconocida')
            ->expectsOutputToContain('No se creó ninguna sucursal automáticamente')
            ->assertExitCode(0);

        // El albarán SÍ se guarda (no se pierde), pero sin vínculo fiscal.
        $albaran = PpqAlbaran::sole();
        $this->assertSame('0999', $albaran->sala_codigo);
        $this->assertNull($albaran->cliente_sucursal_id);
        $this->assertSame($sucursalesAntes, ClienteSucursal::count());
        $this->assertTrue(PpqAlbaran::salaSinResolver()->exists());
    }

    public function test_sala_conocida_solo_por_el_mapa_no_es_excepcion(): void
    {
        PpqSala::recordar('0260', 'Súper Selectos Escalón', 'ccf_json');
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-m', 'AC01/0260/00/1111', '2606026002401', 50.00, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->doesntExpectOutputToContain('EXCEPCIONES')
            ->assertExitCode(0);

        $this->assertFalse(PpqAlbaran::salaSinResolver()->exists());
    }

    public function test_albaran_sin_numero_legible_no_se_guarda_y_se_reporta(): void
    {
        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-sin-numero', '', '26060236004586', 10.00, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->expectsOutputToContain('sin número de albarán legible')
            ->assertExitCode(0);

        $this->assertSame(0, PpqAlbaran::count());
    }

    // ------------------------------------------------------------- guardas

    public function test_falla_limpio_si_gmail_no_esta_disponible(): void
    {
        $this->fakeServicio([], disponible: false);

        $this->artisan('ppq:sincronizar-albaranes')
            ->expectsOutputToContain('Gmail no está disponible')
            ->assertExitCode(1);
    }

    public function test_desconexion_a_mitad_de_corrida_no_revienta(): void
    {
        $this->app->instance(PpqGmailService::class, new class extends PpqGmailService
        {
            public function __construct() {}

            public function disponible(): bool
            {
                return true;
            }

            public function albaranesDeFecha(string $fecha, int $limite = 40): array
            {
                throw new GmailDesconectadoException('La conexión con Gmail expiró o fue revocada.');
            }
        });

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14'])
            ->expectsOutputToContain('Gmail se desconectó durante la sincronización')
            ->assertExitCode(1);
    }

    public function test_rechaza_una_ventana_invertida(): void
    {
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-20', '--hasta' => '2026-07-14'])
            ->expectsOutputToContain('es posterior a')
            ->assertExitCode(1);
    }

    // -------------------------------------------------- end-to-end con GmailClient

    public function test_end_to_end_reusa_gmail_client_y_el_servicio_real(): void
    {
        // Acá NO se dobla PpqGmailService: se dobla solo el GmailClient (la capa de red),
        // así que el Command pasa por el servicio real, su filtro de asunto "albar", el
        // decoder de adjuntos y el parser de DTE. Verifica que la reutilización es real.
        $dte = [
            'identificacion' => ['numeroControl' => 'DTE-03-M001P002-000000000001011', 'tipoDte' => '03', 'fecEmi' => '2026-07-14'],
            'resumen' => ['totalPagar' => 168.88],
            'apendice' => [['campo' => 'ordenCompra', 'valor' => '26060236004586']],
        ];

        $this->app->instance(GmailClient::class, new class($dte) extends GmailClient
        {
            /** @param array<string, mixed> $dte */
            public function __construct(private array $dte) {}

            public function disponible(): bool
            {
                return true;
            }

            protected function listar(string $q, int $limite): array
            {
                return [
                    ['id' => 'msg-e2e', 'snippet' => '', 'asunto' => 'Albarán AC01/0236/00/6359 - ELSA', 'fecha' => '2026-07-14'],
                    // Otro documento del mismo label que NO es albarán: debe filtrarse.
                    ['id' => 'msg-quedan', 'snippet' => '', 'asunto' => 'QUEDAN de julio', 'fecha' => '2026-07-14'],
                ];
            }

            public function adjuntos(string $messageId): array
            {
                return [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode($this->dte)]];
            }
        });

        $this->app->forgetInstance(PpqGmailService::class);
        $sucursal = $this->sala('0236', 'Súper Selectos Santa Elena');

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->assertExitCode(0);

        // Solo el albarán; el QUEDAN quedó fuera por el filtro de asunto del servicio real.
        $albaran = PpqAlbaran::sole();
        $this->assertSame('AC01/0236/00/6359', $albaran->numero_albaran);
        $this->assertSame('26060236004586', $albaran->numero_orden_compra); // OC salida del JSON
        $this->assertSame('168.88', (string) $albaran->monto_albaran);
        $this->assertSame($sucursal->id, $albaran->cliente_sucursal_id);
    }

    public function test_el_servicio_real_se_arma_con_las_piezas_esperadas(): void
    {
        // Guarda de reutilización: el Command no debe traer su propio cliente ni parser.
        $servicio = app(PpqGmailService::class);
        $refleja = fn (string $prop) => (new \ReflectionProperty(PpqGmailService::class, $prop))->getValue($servicio);

        $this->assertInstanceOf(GmailClient::class, $refleja('gmail'));
        $this->assertInstanceOf(DteCorreoParser::class, $refleja('parser'));
        $this->assertInstanceOf(JsonAdjuntoDecoder::class, $refleja('decoder'));
        $this->assertInstanceOf(AlbaranParser::class, $refleja('albaranParser'));
    }

    // ------------------------------------------------------------- scheduler

    public function test_esta_agendado_y_con_aplicar(): void
    {
        $comandos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

        $agendado = $comandos->first(fn (?string $c) => $c !== null && str_contains($c, 'ppq:sincronizar-albaranes'));

        $this->assertNotNull($agendado, 'El comando debería estar agendado en routes/console.php.');
        // Sin --aplicar la corrida agendada sería un dry-run: correría todos los días
        // sin guardar nada, y nadie se enteraría.
        $this->assertStringContainsString('--aplicar', $agendado);
    }

    // ------------------------------------------------------- albarán dado de baja

    public function test_un_albaran_dado_de_baja_se_omite_sin_abortar(): void
    {
        // Albarán capturado desde la pantalla (sin gmail_message_id, así que el guard por
        // message id no lo tapa) y después dado de baja. El índice único no distingue
        // borrados: sin el guard con withTrashed() esto reventaba la corrida entera.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            'origen' => 'manual',
        ])->delete();

        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->expectsOutputToContain('DADOS DE BAJA')
            ->assertExitCode(0);

        // No se resucitó ni se insertó un duplicado.
        $this->assertSame(0, PpqAlbaran::count());
        $this->assertSame(1, PpqAlbaran::onlyTrashed()->count());
    }

    public function test_la_corrida_sigue_con_los_demas_correos_despues_de_uno_dado_de_baja(): void
    {
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            'origen' => 'manual',
        ])->delete();

        $this->fakeServicio(['2026-07-14' => [
            $this->candidato('msg-1', 'AC01/0236/00/1111', '26060236001111', 10.00, '2026-07-14'),
            $this->candidato('msg-2', 'AC01/0236/00/6359', '26060236004586', 250.75, '2026-07-14'), // dado de baja
            $this->candidato('msg-3', 'AC01/0236/00/3333', '26060236003333', 30.00, '2026-07-14'),
        ]]);

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true])
            ->assertExitCode(0);

        // Los de antes Y los de después del caso problemático se guardaron igual.
        $this->assertSame(2, PpqAlbaran::count());
        $this->assertTrue(PpqAlbaran::where('numero_albaran', 'AC01/0236/00/1111')->exists());
        $this->assertTrue(PpqAlbaran::where('numero_albaran', 'AC01/0236/00/3333')->exists());
    }

    // ------------------------------------------------------------- truncado

    public function test_un_dia_truncado_se_advierte_y_frena_la_marca(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-16');

        // El día 18 vino lleno: Gmail dejó correos sin devolver.
        $this->fakeServicio([], true, ['2026-07-18']);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0, '--aplicar' => true])
            ->expectsOutputToContain('VENTANA TRUNCADA')
            ->expectsOutputToContain('La marca de progreso se dejó antes del día truncado')
            ->assertExitCode(0);

        // La marca se planta ANTES del día truncado, no en `hasta`: el 18 se vuelve a leer.
        $this->assertSame('2026-07-17', $this->marca());

        Carbon::setTestNow();
    }

    public function test_sin_truncado_la_marca_llega_hasta_el_final_de_la_ventana(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-16');
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0, '--aplicar' => true])->assertExitCode(0);

        $this->assertSame('2026-07-20', $this->marca());

        Carbon::setTestNow();
    }

    // --------------------------------------------------- marca de progreso

    public function test_el_dry_run_no_mueve_la_marca(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-16');
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0])->assertExitCode(0);

        $this->assertSame('2026-07-16', $this->marca());

        Carbon::setTestNow();
    }

    public function test_una_desconexion_a_mitad_de_corrida_no_mueve_la_marca(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-16');

        $this->app->instance(PpqGmailService::class, new class extends PpqGmailService
        {
            public function __construct() {}

            public function disponible(): bool
            {
                return true;
            }

            public function albaranesDeFecha(string $fecha, int $limite = 40): array
            {
                throw new GmailDesconectadoException('token revocado');
            }

            public function ultimaBusquedaTruncada(): bool
            {
                return false;
            }
        });

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0, '--aplicar' => true])->assertExitCode(1);

        $this->assertSame('2026-07-16', $this->marca());

        Carbon::setTestNow();
    }

    public function test_la_marca_ignora_una_fecha_de_albaran_mal_parseada(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-16');

        // Un albarán ya sincronizado con el año mal parseado. Con el ancla vieja
        // (max fecha_albaran) la ventana saltaba a 2027 y el comando quedaba plantado.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/9', 'numero_orden_compra' => 'OC-MALA',
            'fecha_albaran' => '2027-07-14', 'origen' => 'gmail', 'gmail_message_id' => 'msg-malo',
        ]);
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0, '--aplicar' => true])
            ->expectsOutputToContain('Ventana: 2026-07-17 → 2026-07-20')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_una_corrida_acotada_hacia_adelante_no_mueve_la_marca(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-10');
        $this->fakeServicio([]);

        // Corrida a mano de un solo día: entre el 11 y el 19 quedan correos sin leer, así
        // que mover la marca a hoy los dejaría afuera para siempre.
        $this->artisan('ppq:sincronizar-albaranes', ['--dias' => 1, '--aplicar' => true])
            ->expectsOutputToContain('La marca de progreso NO se movió')
            ->expectsOutputToContain('--desde 2026-07-11')
            ->assertExitCode(0);

        $this->assertSame('2026-07-10', $this->marca());

        Carbon::setTestNow();
    }

    public function test_la_marca_nunca_retrocede(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-19');
        $this->fakeServicio([]);

        // Recorrer días ya cubiertos no baja la marca.
        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-15', '--hasta' => '2026-07-17', '--aplicar' => true])
            ->assertExitCode(0);

        $this->assertSame('2026-07-19', $this->marca());

        Carbon::setTestNow();
    }

    public function test_se_recupera_tras_varios_dias_apagado(): void
    {
        // El servidor estuvo 10 días sin correr: la ventana arranca en la marca y llega
        // hasta hoy, así que no se pierde ningún día.
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-09');
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 0, '--aplicar' => true])
            ->expectsOutputToContain('Ventana: 2026-07-10 → 2026-07-20 (11 día/s)')
            ->assertExitCode(0);

        $this->assertSame('2026-07-20', $this->marca());

        Carbon::setTestNow();
    }

    public function test_la_marca_manda_sobre_la_fecha_del_albaran(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $this->ponerMarca('2026-07-18');

        // Hay un albarán fechado mucho antes: la marca (fecha de correo) es la que manda.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/8', 'numero_orden_compra' => 'OC-VIEJA',
            'fecha_albaran' => '2026-07-02', 'origen' => 'gmail',
        ]);
        $this->fakeServicio([]);

        $this->artisan('ppq:sincronizar-albaranes', ['--solape' => 1])
            ->expectsOutputToContain('Última ventana completa: 2026-07-18')
            ->expectsOutputToContain('Ventana: 2026-07-18 → 2026-07-20')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }
}
