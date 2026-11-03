<?php

namespace Tests\Feature\Alertas;

use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Detectores\EventosRecientes;
use App\Services\Alertas\Hallazgo;
use App\Services\Alertas\RegistroEventos;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Reglas del aviso (issue #163): agrupar los repetidos, tope por tipo, «ya se
 * normalizó» al arreglarse y ningún bucle si el correo mismo falla.
 */
class AvisadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'alertas.enabled' => true,
            'alertas.correo' => 'avisos@example.test',
            'alertas.reaviso_minutos' => 60,
            'alertas.tope_diario' => 6,
        ]);

        // En testing el candado simula todo envío; acá se prueba el envío real.
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });

        // El comando completo también corre la revisión de /salud: nada sale a la red.
        Http::fake();
        $this->travelTo(now()->setTime(10, 0));
    }

    public function test_avisa_una_vez_al_abrirse_y_agrupa_los_repetidos(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Algo se rompió', 'Detalle.', 'Revisalo.', conteo: 2));

        $this->assertSame('falla', $this->avisador()->procesar($detector));
        $this->travel(5)->minutes();
        $this->assertNull($this->avisador()->procesar($detector));
        $this->travel(5)->minutes();
        $this->assertNull($this->avisador()->procesar($detector));

        Mail::assertSent(AlertaSistemaCorreo::class, 1);
        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->hasTo('avisos@example.test')
            && $m->accion === 'falla'
            && $m->envelope()->subject === 'Alerta: Algo se rompió');
    }

    public function test_si_empeora_reavisa_solo_despues_del_reaviso_y_en_un_solo_correo(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Fallan trabajos', 'D.', 'Q.', conteo: 1));
        $this->avisador()->procesar($detector);

        $detector->hallazgo = new Hallazgo('Fallan trabajos', 'D.', 'Q.', conteo: 3);
        $this->travel(30)->minutes();
        $this->assertNull($this->avisador()->procesar($detector), 'Antes del reaviso no se escribe aunque empeore.');

        $this->travel(31)->minutes();
        $this->assertSame('sigue', $this->avisador()->procesar($detector));
        $this->travel(61)->minutes();
        $this->assertNull($this->avisador()->procesar($detector), 'Sin empeorar no se repite.');

        Mail::assertSent(AlertaSistemaCorreo::class, 2);
        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->accion === 'sigue'
            && str_starts_with($m->envelope()->subject, 'Sigue la falla:'));
    }

    public function test_manda_ya_se_normalizo_cuando_se_arregla(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Algo se rompió', 'D.', 'Q.'));
        $this->avisador()->procesar($detector);

        $detector->hallazgo = null;
        $this->travel(5)->minutes();

        $this->assertSame('normalizado', $this->avisador()->procesar($detector));
        $this->assertNull($this->avisador()->procesar($detector), 'El normalizado sale una sola vez.');

        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->accion === 'normalizado'
            && $m->envelope()->subject === 'Ya se normalizó: Prueba'
            && str_contains($m->render(), '10:00'));
        Mail::assertSent(AlertaSistemaCorreo::class, 2);
    }

    public function test_una_falla_que_va_y_viene_no_pasa_del_tope_diario(): void
    {
        config(['alertas.tope_diario' => 3]);
        Mail::fake();
        $detector = $this->detector(null);

        for ($i = 0; $i < 20; $i++) {
            $detector->hallazgo = $i % 2 === 0 ? new Hallazgo('Va y viene', 'D.', 'Q.') : null;
            $this->avisador()->procesar($detector);
            $this->travel(5)->minutes();
        }

        Mail::assertSent(AlertaSistemaCorreo::class, 3);
        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->ultimoDelDia
            && str_contains($m->render(), 'tope diario'));

        // Al día siguiente el tope vuelve a empezar.
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $detector->hallazgo = new Hallazgo('Va y viene', 'D.', 'Q.');
        $this->avisador()->procesar($detector);
        Mail::assertSent(AlertaSistemaCorreo::class, 4);
    }

    public function test_si_el_correo_falla_no_entra_en_bucle(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('SMTP caído'));
        $detector = $this->detector(new Hallazgo('Algo se rompió', 'D.', 'Q.'));

        $this->assertNull($this->avisador()->procesar($detector));

        // Ni reintenta en la próxima corrida ni anota la falla del correo como evento.
        $this->travel(5)->minutes();
        $this->assertNull($this->avisador()->procesar($detector));
        $this->assertNull(RegistroEventos::leer(RegistroEventos::ERRORES_500));
        $this->assertNull(RegistroEventos::leer(RegistroEventos::TAREAS));
    }

    public function test_el_comando_termina_bien_aunque_el_correo_falle(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('SMTP caído'));
        RegistroEventos::anotar(RegistroEventos::ERRORES_500, 'ruta.prueba · RuntimeException');

        $this->artisan('alertas:revisar')->assertSuccessful();
    }

    public function test_apagado_o_sin_destinatario_no_manda_nada(): void
    {
        Mail::fake();
        RegistroEventos::anotar(RegistroEventos::ERRORES_500, 'ruta.prueba · RuntimeException');

        config(['alertas.correo' => '']);
        $this->artisan('alertas:revisar')->expectsOutputToContain('ALERTAS_CORREO')->assertSuccessful();

        config(['alertas.enabled' => false, 'alertas.correo' => 'avisos@example.test']);
        $this->artisan('alertas:revisar')->expectsOutputToContain('apagados')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_un_error_500_se_avisa_sin_el_mensaje_de_la_excepcion(): void
    {
        Mail::fake();
        Route::get('/_prueba-500', fn () => throw new RuntimeException('clave-secreta-123'))->name('prueba.quinientos');

        $this->get('/_prueba-500')->assertStatus(500);
        $this->get('/_prueba-500')->assertStatus(500);

        $this->assertSame('falla', $this->avisador()->procesar(EventosRecientes::errores500()));

        Mail::assertSent(AlertaSistemaCorreo::class, function (AlertaSistemaCorreo $m) {
            $html = $m->render();

            return str_contains($m->envelope()->subject, 'Errores 500 en el sistema: 2')
                && str_contains($html, 'prueba.quinientos · RuntimeException (2)')
                && ! str_contains($html, 'clave-secreta-123');
        });

        // Sin errores nuevos durante la ventana, se normaliza y el contador vuelve a cero.
        $this->travel(31)->minutes();
        $this->assertSame('normalizado', $this->avisador()->procesar(EventosRecientes::errores500()));
        $this->assertNull(RegistroEventos::leer(RegistroEventos::ERRORES_500));
    }

    public function test_una_tarea_programada_que_falla_se_anota_con_su_comando(): void
    {
        $tarea = app(Schedule::class)->command('backup:run');

        event(new ScheduledTaskFailed($tarea, new RuntimeException('exit 1')));

        $evento = RegistroEventos::leer(RegistroEventos::TAREAS);
        $this->assertSame(['backup:run' => 1], $evento['etiquetas']);
    }

    public function test_con_los_avisos_apagados_no_se_anota_nada(): void
    {
        config(['alertas.enabled' => false]);

        RegistroEventos::anotar(RegistroEventos::ERRORES_500, 'x');

        $this->assertNull(RegistroEventos::leer(RegistroEventos::ERRORES_500));
    }

    public function test_un_detector_fuera_de_horario_no_cierra_el_aviso_abierto(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Lector callado', 'D.', 'Q.'));
        $this->avisador()->procesar($detector);

        $detector->aplica = false;
        $detector->hallazgo = null;
        $this->assertNull($this->avisador()->procesar($detector));

        $detector->aplica = true;
        $this->assertSame('normalizado', $this->avisador()->procesar($detector));
    }

    public function test_un_detector_roto_no_frena_a_los_demas(): void
    {
        Mail::fake();
        $roto = $this->detector(null);
        $roto->explota = true;
        $sano = $this->detector(new Hallazgo('Otro problema', 'D.', 'Q.'), 'otro');

        $this->assertSame(['otro' => 'falla'], $this->avisador()->revisar([$roto, $sano]));
    }

    private function avisador(): Avisador
    {
        return app(Avisador::class);
    }

    private function detector(?Hallazgo $hallazgo, string $tipo = 'prueba'): Detector
    {
        return new class($hallazgo, $tipo) implements Detector
        {
            public bool $aplica = true;

            public bool $explota = false;

            public function __construct(public ?Hallazgo $hallazgo, private string $clave) {}

            public function tipo(): string
            {
                return $this->clave;
            }

            public function nombre(): string
            {
                return 'Prueba';
            }

            public function aplica(): bool
            {
                return $this->aplica;
            }

            public function revisar(): ?Hallazgo
            {
                if ($this->explota) {
                    throw new RuntimeException('tabla inexistente');
                }

                return $this->hallazgo;
            }
        };
    }
}
