<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * EL RESPALDO DIARIO EN EL PLANIFICADOR (P5 de docs/MIGRACION_LINUX_DOCKER.md).
 *
 * En Windows `backup:mysql-diario` lo lanza la tarea «DTE Backup Diario» a las 02:00. En
 * Linux con Docker esa tarea no existe y lo corre el planificador, pero solo si
 * BACKUP_DIARIO_PROGRAMADO está encendido: apagado por defecto, para que encender
 * `schedule:run` en Windows no duplique el volcado.
 *
 * La tarea se registra o no al leer `routes/console.php`, así que cada prueba vuelve a
 * leer ese archivo sobre un planificador vacío con el interruptor ya fijado.
 */
class BackupDiarioProgramadoTest extends TestCase
{
    /** @return list<Event> */
    private function tareasDelRespaldoCon(bool $encendido): array
    {
        config(['backup_diario.programado' => $encendido]);
        ScheduleFacade::swap(new Schedule(config('app.schedule_timezone') ?? config('app.timezone')));

        require base_path('routes/console.php');

        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $evento) => str_contains((string) $evento->command, 'backup:mysql-diario'),
        ));
    }

    public function test_apagado_por_defecto(): void
    {
        $this->assertFalse(
            (bool) config('backup_diario.programado'),
            'el respaldo programado no puede venir encendido: en Windows lo corre su propia tarea'
        );
    }

    public function test_apagado_no_se_programa_ni_aparece_en_schedule_list(): void
    {
        $this->assertSame([], $this->tareasDelRespaldoCon(false));

        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('backup:mysql-diario')
            ->assertSuccessful();
    }

    public function test_encendido_corre_una_vez_a_las_dos_como_automatico(): void
    {
        $tareas = $this->tareasDelRespaldoCon(true);

        $this->assertCount(1, $tareas);
        $this->assertSame('0 2 * * *', $tareas[0]->expression);
        $this->assertStringContainsString('--origen=automatico', (string) $tareas[0]->command);
        $this->assertTrue($tareas[0]->withoutOverlapping, 'dos volcados a la vez no pueden pisarse');
        $this->assertTrue($tareas[0]->filtersPass($this->app));

        $this->artisan('schedule:list')
            ->expectsOutputToContain('backup:mysql-diario')
            ->assertSuccessful();
    }
}
