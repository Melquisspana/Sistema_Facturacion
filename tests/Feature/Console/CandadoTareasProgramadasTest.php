<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * EL CANDADO DE UNA TAREA PROGRAMADA NO PUEDE DURAR UN DÍA (simulacro de contingencia
 * del 17/11/2026, falla F2).
 *
 * `withoutOverlapping()` sin minutos deja un candado de 1440 (24 h). En producción la
 * caché es la base, así que el candado sobrevive al reinicio: si el programador muere a
 * mitad de una tarea (despliegue, reinicio, corte de luz), esa tarea no vuelve a correr
 * hasta el día siguiente y nada avisa. Pasó con `dte:contingencia-lotes`.
 */
class CandadoTareasProgramadasTest extends TestCase
{
    /** El candado más largo que tiene hoy una tarea: el respaldo diario, de 60 minutos. */
    private const TOPE_MINUTOS = 60;

    /**
     * Todas las tareas de `routes/console.php`, incluido el respaldo diario, que solo se
     * registra con su interruptor encendido.
     *
     * @return list<Event>
     */
    private function tareas(): array
    {
        config(['backup_diario.programado' => true]);
        ScheduleFacade::swap(new Schedule(config('app.schedule_timezone') ?? config('app.timezone')));

        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    private function tarea(string $comando): Event
    {
        $tareas = array_values(array_filter(
            $this->tareas(),
            fn (Event $evento) => str_contains((string) $evento->command, $comando),
        ));

        $this->assertCount(1, $tareas, "se esperaba una sola tarea programada de {$comando}");

        return $tareas[0];
    }

    public function test_las_tareas_de_contingencia_se_destraban_solas_en_minutos(): void
    {
        $lotes = $this->tarea('dte:contingencia-lotes');
        $this->assertTrue($lotes->withoutOverlapping);
        $this->assertSame(10, $lotes->expiresAt);

        $reconexion = $this->tarea('dte:contingencia-reconexion');
        $this->assertTrue($reconexion->withoutOverlapping);
        $this->assertSame(15, $reconexion->expiresAt);
    }

    public function test_ninguna_tarea_programada_conserva_el_candado_de_un_dia(): void
    {
        $conCandado = array_filter($this->tareas(), fn (Event $evento) => $evento->withoutOverlapping);

        $this->assertNotEmpty($conCandado);

        foreach ($conCandado as $evento) {
            $this->assertLessThanOrEqual(
                self::TOPE_MINUTOS,
                $evento->expiresAt,
                "{$evento->command}: el candado dura {$evento->expiresAt} minutos. Si la tarea muere a la mitad, queda frenada todo ese tiempo."
            );
        }
    }
}
