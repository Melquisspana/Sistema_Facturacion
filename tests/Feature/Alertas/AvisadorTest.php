<?php

namespace Tests\Feature\Alertas;

use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Detectores\EventosRecientes;
use App\Services\Alertas\Hallazgo;
use App\Services\Alertas\RegistroEventos;
use App\Support\Correo\CandadoCorreoReal;
use App\Support\HoraNegocio;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

    public function test_el_estado_guarda_el_texto_del_aviso_y_lo_actualiza_aunque_no_mande_correo(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Algo se rompió', 'Detalle uno.', 'Revisalo.', conteo: 2, enlace: 'admin.salud-sistema'));
        $this->avisador()->procesar($detector);

        $estado = Cache::get('alertas.abiertas.prueba');
        $this->assertSame(['Prueba', 'Algo se rompió', 'Detalle uno.', 'Revisalo.', 'admin.salud-sistema'],
            [$estado['nombre'], $estado['titulo'], $estado['detalle'], $estado['que_hacer'], $estado['enlace']]);

        // Mejoró: no sale correo, pero el Inicio tiene que ver el texto nuevo.
        $detector->hallazgo = new Hallazgo('Algo se rompió menos', 'Detalle dos.', 'Otra cosa.', conteo: 1);
        $this->travel(5)->minutes();
        $this->assertNull($this->avisador()->procesar($detector));
        $estado = Cache::get('alertas.abiertas.prueba');
        $this->assertSame(['Algo se rompió menos', 'Detalle dos.', 'Otra cosa.', null],
            [$estado['titulo'], $estado['detalle'], $estado['que_hacer'], $estado['enlace']]);
        $this->assertSame(1, $estado['conteo_avisado']);
    }

    public function test_abiertos_lista_los_avisos_del_mas_viejo_al_mas_nuevo_y_dice_si_el_correo_no_salio(): void
    {
        config(['alertas.tope_diario' => 1]);
        Mail::fake();
        $this->avisador()->revisar([]);
        $viejo = $this->detector(new Hallazgo('Viejo', 'D.', 'Q.', desde: now()->subHour(), enlace: 'admin.salud-sistema'), 'salud');
        $nuevo = $this->detector(new Hallazgo('Nuevo', 'D2.', 'Q2.', desde: now()->subMinutes(5), enlace: 'ruta.que.no.existe'), 'trabajos_fallidos');
        $this->avisador()->procesar($nuevo);
        $this->avisador()->procesar($viejo);
        $tope = $this->detector(new Hallazgo('Sin correo', 'D3.', 'Q3.'), 'dte_rechazados');
        Cache::put('alertas.envios.dte_rechazados.'.HoraNegocio::fechaHoy(), 1, now()->addDay());
        $this->avisador()->procesar($tope);

        $abiertos = $this->avisador()->abiertos();

        $this->assertNull($abiertos['estado']);
        $this->assertSame(['Viejo', 'Nuevo', 'Sin correo'], array_column($abiertos['avisos'], 'titulo'));
        $this->assertSame([true, true, false], array_column($abiertos['avisos'], 'correo_salio'));
        $this->assertSame(route('admin.salud-sistema'), $abiertos['avisos'][0]['enlace']);
        $this->assertNull($abiertos['avisos'][1]['enlace'], 'Una ruta que no existe no da un enlace roto.');
        $this->assertSame('Prueba', $abiertos['avisos'][0]['nombre']);
        $this->assertSame(HoraNegocio::aLocal(now()->subHour())->format('d/m/Y H:i'), $abiertos['avisos'][0]['desde']->format('d/m/Y H:i'));

        // Uno que se normaliza sale de la lista; uno que no opina se queda.
        $nuevo->hallazgo = null;
        $this->avisador()->procesar($nuevo);
        $viejo->aplica = false;
        $viejo->hallazgo = null;
        $this->avisador()->procesar($viejo);
        $this->assertSame(['Viejo', 'Sin correo'], array_column($this->avisador()->abiertos()['avisos'], 'titulo'));
    }

    public function test_el_correo_de_falla_dice_que_el_aviso_tambien_se_ve_en_el_inicio(): void
    {
        Mail::fake();
        $detector = $this->detector(new Hallazgo('Algo se rompió', 'D.', 'Q.'));
        $this->avisador()->procesar($detector);
        $detector->hallazgo = null;
        $this->avisador()->procesar($detector);

        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->accion === 'falla'
            && str_contains($m->render(), 'este aviso también se ve en el Inicio'));
        Mail::assertSent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->accion === 'normalizado'
            && ! str_contains($m->render(), 'se ve en el Inicio'));
    }

    public function test_abiertos_usa_el_nombre_para_un_estado_viejo_sin_titulo(): void
    {
        $this->avisador()->revisar([]);
        Cache::forever('alertas.abiertas.salud', ['desde' => now()->getTimestamp(), 'avisada' => true, 'conteo_avisado' => 1, 'ultimo_intento' => null]);

        $aviso = $this->avisador()->abiertos()['avisos'][0];

        $this->assertSame('Salud del sistema', $aviso['titulo']);
        $this->assertSame('', $aviso['detalle']);
        $this->assertNull($aviso['enlace']);
    }

    public function test_abiertos_dice_si_los_avisos_estan_apagados_sin_correo_o_atrasados(): void
    {
        Cache::forever('alertas.abiertas.salud', ['desde' => now()->getTimestamp(), 'avisada' => true, 'conteo_avisado' => 1, 'ultimo_intento' => null, 'titulo' => 'Abierto']);

        config(['alertas.enabled' => false]);
        $this->assertSame(['estado' => 'apagado', 'revisado_en' => null, 'avisos' => []], $this->avisador()->abiertos());

        config(['alertas.enabled' => true, 'alertas.correo' => '']);
        $this->assertSame('sin_correo', $this->avisador()->abiertos()['estado']);
        $this->assertSame([], $this->avisador()->abiertos()['avisos']);

        config(['alertas.correo' => 'avisos@example.test']);
        $this->assertSame('atrasado', $this->avisador()->abiertos()['estado'], 'Nunca se revisó.');
        $this->avisador()->revisar([]);
        $this->assertNull($this->avisador()->abiertos()['estado']);
        $this->travel(16)->minutes();
        $abiertos = $this->avisador()->abiertos();
        $this->assertSame('atrasado', $abiertos['estado']);
        $this->assertSame(['Abierto'], array_column($abiertos['avisos'], 'titulo'), 'Atrasados se muestran igual.');
    }

    public function test_abiertos_lee_la_cache_de_los_avisos_una_sola_vez(): void
    {
        $sueltas = 0;
        $juntas = 0;
        Event::listen(RetrievingKey::class, function (RetrievingKey $e) use (&$sueltas) {
            $sueltas += str_starts_with($e->key, 'alertas.') ? 1 : 0;
        });
        Event::listen(RetrievingManyKeys::class, function () use (&$juntas) {
            $juntas++;
        });

        $this->avisador()->abiertos();

        $this->assertSame(1, $juntas);
        $this->assertSame(0, $sueltas);
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
