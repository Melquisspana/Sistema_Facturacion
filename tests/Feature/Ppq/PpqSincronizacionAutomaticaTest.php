<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqAlbaran;
use App\Services\Ppq\PpqGmailService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL INTERRUPTOR DE LA SINCRONIZACIÓN AUTOMÁTICA DE ALBARANES.
 *
 * PPQ era la única de las cuatro tareas de `routes/console.php` sin `when()`. La
 * consecuencia concreta: el día que alguien registrara `php artisan schedule:run` en el
 * servidor —un paso de instalación, no una decisión sobre PPQ—, el módulo habría
 * empezado a consultar Gmail cada cinco minutos y a escribir en `ppq_albaranes` sin que
 * nadie lo hubiera encendido. Instalar el planificador no puede activar un módulo de
 * rebote.
 *
 * `PPQ_ALBARANES_AUTO_SYNC` (apagado por defecto) cierra DOS puertas, y las dos se
 * prueban acá:
 *
 *   1. la tarea programada, que con la llave apagada no se ejecuta;
 *   2. el propio comando con `--aplicar`, para que una invocación accidental por fuera
 *      del planificador tampoco consulte el correo.
 *
 * El dry-run NO se bloquea a propósito: es el paso con el que se comprueba que todo
 * funciona ANTES de encender la automática. Ver docs/PPQ_ALBARANES_AUTOMATICO.md.
 */
class PpqSincronizacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    /** La tarea programada de albaranes, tal como la define `routes/console.php`. */
    private function tareaDeAlbaranes(): Event
    {
        foreach (app(Schedule::class)->events() as $evento) {
            if (str_contains((string) $evento->command, 'ppq:sincronizar-albaranes')) {
                return $evento;
            }
        }

        $this->fail('La tarea programada de albaranes no está definida.');
    }

    /**
     * Doble de Gmail que GRITA si alguien lo toca. No devuelve datos: su único trabajo
     * es fallar la prueba en el momento exacto en que el comando intente consultar el
     * correo con el interruptor apagado.
     */
    private function gmailQueNoDebeUsarse(): object
    {
        $doble = new class extends PpqGmailService
        {
            public bool $consultado = false;

            public function __construct()
            {
                // No se llama a parent::__construct: este doble no toca Gmail.
            }

            public function disponible(): bool
            {
                $this->consultado = true;

                return true;
            }

            public function albaranesDeFecha(string $fecha, int $limite = 40): array
            {
                $this->consultado = true;

                return [];
            }

            public function ultimaBusquedaTruncada(): bool
            {
                return false;
            }
        };

        $this->app->instance(PpqGmailService::class, $doble);

        return $doble;
    }

    // ------------------------------------------------------------------- apagado

    public function test_apagado_por_defecto(): void
    {
        $this->assertFalse(
            (bool) config('ppq.albaranes.sincronizacion_automatica'),
            'la sincronización automática no puede venir encendida de fábrica'
        );
    }

    /** Con la llave apagada, la tarea existe pero el planificador no la ejecuta. */
    public function test_apagado_la_tarea_programada_no_se_ejecuta(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => false]);

        $tarea = $this->tareaDeAlbaranes();

        $this->assertFalse($tarea->filtersPass($this->app), 'la tarea correría con el interruptor apagado');
    }

    /**
     * `schedule:list` sigue mostrándola: la definición existe para poder inspeccionarla
     * y probarla. Lo que la llave decide es si se EJECUTA, no si aparece.
     */
    public function test_apagado_la_tarea_sigue_siendo_visible_en_schedule_list(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => false]);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('ppq:sincronizar-albaranes')
            ->assertSuccessful();
    }

    /** Y una invocación a mano con --aplicar tampoco consulta Gmail ni escribe. */
    public function test_apagado_el_comando_con_aplicar_no_consulta_gmail_ni_escribe(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => false]);
        $gmail = $this->gmailQueNoDebeUsarse();

        $this->artisan('ppq:sincronizar-albaranes', [
            '--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true,
        ])
            ->expectsOutputToContain('PPQ_ALBARANES_AUTO_SYNC=false')
            ->assertExitCode(1);

        $this->assertFalse($gmail->consultado, 'el comando llegó a tocar Gmail con el interruptor apagado');
        $this->assertSame(0, PpqAlbaran::count());
    }

    // ------------------------------------------------------------------ encendido

    public function test_encendido_la_tarea_programada_si_se_ejecuta(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => true]);

        $this->assertTrue($this->tareaDeAlbaranes()->filtersPass($this->app));
    }

    /** Encendido, el comando pasa la puerta y llega a la mecánica normal. */
    public function test_encendido_el_comando_con_aplicar_se_ejecuta(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => true]);
        $gmail = $this->gmailQueNoDebeUsarse();

        $this->artisan('ppq:sincronizar-albaranes', [
            '--desde' => '2026-07-14', '--hasta' => '2026-07-14', '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertTrue($gmail->consultado, 'con el interruptor encendido el comando sí debe llegar a Gmail');
    }

    // -------------------------------------------------------------------- dry-run

    /**
     * El dry-run sigue disponible con la llave apagada —es el paso previo del
     * procedimiento— y sigue sin escribir nada.
     */
    public function test_el_dry_run_sigue_disponible_y_no_escribe(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => false]);
        $this->gmailQueNoDebeUsarse();

        $this->artisan('ppq:sincronizar-albaranes', ['--desde' => '2026-07-14', '--hasta' => '2026-07-14'])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(0, PpqAlbaran::count());
    }

    /** La otra automática del proyecto sigue apagada, y no la toca este cambio. */
    public function test_compras_sigue_apagada_por_defecto(): void
    {
        $this->assertFalse((bool) config('documentos_recibidos.sincronizacion_automatica'));
    }
}
