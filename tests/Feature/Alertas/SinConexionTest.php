<?php

namespace Tests\Feature\Alertas;

use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\ComprobadorConexion;
use App\Services\Alertas\ComprobadorConexionTcp;
use App\Services\Alertas\Detectores\EventosRecientes;
use App\Services\Alertas\RegistroEventos;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\ConexionFalsa;
use Tests\TestCase;

/**
 * Cuando se corta la red, todas las tareas que usan internet fallan juntas. En vez de
 * «Tareas programadas con error: 18», llega un solo aviso de que no hay conexión.
 */
class SinConexionTest extends TestCase
{
    use RefreshDatabase;

    private ConexionFalsa $conexion;

    protected function setUp(): void
    {
        parent::setUp();

        config(['alertas.enabled' => true, 'alertas.correo' => 'avisos@example.test', 'alertas.reaviso_minutos' => 60]);
        $this->conexion = new ConexionFalsa(hay: false);
        $this->app->instance(ComprobadorConexion::class, $this->conexion);

        // En testing el candado simula todo envío; acá se prueba el envío real.
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Http::fake();
        $this->travelTo(now()->setTime(8, 45));
    }

    public function test_un_corte_de_red_que_tumba_18_tareas_manda_un_solo_aviso_de_sin_conexion(): void
    {
        Mail::fake();

        $this->seCortaLaRed(fallas: 18);

        $hechos = app(Avisador::class)->revisar();

        $this->assertSame('falla', $hechos[RegistroEventos::SIN_CONEXION] ?? null);
        $this->assertArrayNotHasKey(RegistroEventos::TAREAS, $hechos);
        $avisos = $this->avisosDeTareas();
        $this->assertCount(1, $avisos);
        $correo = $avisos->first();
        $html = $correo->render();
        $this->assertSame('Alerta: Sin conexión a internet', $correo->envelope()->subject);
        $this->assertStringContainsString('ppq:sincronizar-albaranes (5)', $html);
        $this->assertStringContainsString('cobros:leer-correos (4)', $html);
        $this->assertStringContainsString('no hace falta correrlas a mano', $html);
        $this->assertStringNotContainsString('18', $correo->envelope()->subject);

        // Mientras siga cortada, más fallas no mandan otro correo antes del reaviso.
        $this->seCortaLaRed(fallas: 4);
        app(Avisador::class)->revisar();
        $this->assertCount(1, $this->avisosDeTareas());
    }

    public function test_cuando_vuelve_la_conexion_se_normaliza(): void
    {
        Mail::fake();
        $this->seCortaLaRed(fallas: 8);
        app(Avisador::class)->revisar();

        // Volvió la red: ninguna tarea falla durante la ventana de 30 minutos.
        $this->travel(31)->minutes();
        $hechos = app(Avisador::class)->revisar();

        $this->assertSame('normalizado', $hechos[RegistroEventos::SIN_CONEXION] ?? null);
        $this->assertNull(RegistroEventos::leer(RegistroEventos::SIN_CONEXION));
        $this->assertSame(['Alerta: Sin conexión a internet', 'Ya se normalizó: Conexión a internet'],
            $this->avisosDeTareas()->map(fn (AlertaSistemaCorreo $m) => $m->envelope()->subject)->values()->all());
    }

    public function test_una_tarea_que_falla_sin_internet_se_anota_como_sin_conexion_y_no_como_tarea(): void
    {
        $this->fallaLaTarea('ppq:sincronizar-albaranes');

        $this->assertSame(['ppq:sincronizar-albaranes' => 1], RegistroEventos::leer(RegistroEventos::SIN_CONEXION)['etiquetas']);
        $this->assertNull(RegistroEventos::leer(RegistroEventos::TAREAS));
    }

    public function test_una_tarea_que_falla_con_internet_se_anota_como_tarea(): void
    {
        $this->conexion->hay = true;

        $this->fallaLaTarea('ppq:sincronizar-albaranes');

        $this->assertSame(['ppq:sincronizar-albaranes' => 1], RegistroEventos::leer(RegistroEventos::TAREAS)['etiquetas']);
        $this->assertNull(RegistroEventos::leer(RegistroEventos::SIN_CONEXION));
    }

    public function test_la_prueba_de_conexion_se_guarda_un_minuto_y_no_se_repite_en_cada_falla(): void
    {
        foreach (['ppq:sincronizar-albaranes', 'ordenes:sincronizar', 'compras:sincronizar', 'cobros:leer-correos'] as $tarea) {
            $this->fallaLaTarea($tarea);
        }
        $this->assertSame(1, $this->conexion->llamadas);

        $this->travel(61)->seconds();
        $this->fallaLaTarea('ordenes:sincronizar');

        $this->assertSame(2, $this->conexion->llamadas);
        $this->assertSame(5, RegistroEventos::leer(RegistroEventos::SIN_CONEXION)['cantidad']);
    }

    public function test_si_la_prueba_de_conexion_falla_la_tarea_se_anota_como_siempre(): void
    {
        $this->conexion->explota = true;

        $this->fallaLaTarea('ordenes:sincronizar');

        $this->assertSame(['ordenes:sincronizar' => 1], RegistroEventos::leer(RegistroEventos::TAREAS)['etiquetas']);
        $this->assertNull(RegistroEventos::leer(RegistroEventos::SIN_CONEXION));
    }

    public function test_con_los_avisos_apagados_ni_prueba_la_conexion_ni_anota(): void
    {
        config(['alertas.enabled' => false]);

        $this->fallaLaTarea('ordenes:sincronizar');

        $this->assertSame(0, $this->conexion->llamadas);
        $this->assertNull(RegistroEventos::leer(RegistroEventos::SIN_CONEXION));
        $this->assertNull(RegistroEventos::leer(RegistroEventos::TAREAS));
    }

    public function test_la_prueba_real_da_que_hay_internet_si_contesta_alguno_de_los_destinos(): void
    {
        $this->app->forgetInstance(ComprobadorConexion::class);
        $this->assertInstanceOf(ComprobadorConexionTcp::class, app(ComprobadorConexion::class));

        // Sin salir de la máquina: un puerto que escucha y otro que ya no.
        $abierto = stream_socket_server('tcp://127.0.0.1:0');
        $cerrado = stream_socket_server('tcp://127.0.0.1:0');
        $destinoCerrado = stream_socket_get_name($cerrado, false);
        fclose($cerrado);

        $this->assertTrue((new ComprobadorConexionTcp([$destinoCerrado, stream_socket_get_name($abierto, false)], 0.5))->hayInternet());
        fclose($abierto);
        $this->assertFalse((new ComprobadorConexionTcp([$destinoCerrado], 0.5))->hayInternet());
    }

    /**
     * Los correos de tareas y de conexión. Los demás avisos (en una base vacía, «No hay
     * respaldo de la base de hoy») no son parte de esta prueba.
     */
    private function avisosDeTareas(): Collection
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => in_array(
            $m->nombre, [EventosRecientes::tareasProgramadas()->nombre(), EventosRecientes::sinConexion()->nombre()], true,
        ));
    }

    /** Cada tres minutos falla una de las cuatro tareas que usan Gmail. */
    private function seCortaLaRed(int $fallas): void
    {
        $tareas = ['ppq:sincronizar-albaranes', 'ordenes:sincronizar', 'compras:sincronizar', 'cobros:leer-correos'];

        for ($i = 0; $i < $fallas; $i++) {
            $this->fallaLaTarea($tareas[$i % 4]);
            $this->travel(3)->minutes();
        }
    }

    private function fallaLaTarea(string $comando): void
    {
        event(new ScheduledTaskFailed(
            app(Schedule::class)->command($comando),
            new RuntimeException('Could not resolve host: gmail.googleapis.com'),
        ));
    }
}
