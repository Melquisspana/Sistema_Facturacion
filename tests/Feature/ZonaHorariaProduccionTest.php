<?php

namespace Tests\Feature;

use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\HoraOficial;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Producción guarda en hora de El Salvador (APP_TIMEZONE=America/El_Salvador). Desarrollo
 * y CI usan la misma zona por defecto para que las pruebas corran como producción
 * (decisión 0004, corrección del 2026-10-06; issue #59).
 */
class ZonaHorariaProduccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_zona_de_guardado_por_defecto_es_la_de_negocio(): void
    {
        $this->assertSame('America/El_Salvador', config('app.timezone'));
        $this->assertSame(HoraNegocio::zona(), config('app.timezone'));
        $this->assertSame('America/El_Salvador', date_default_timezone_get());
    }

    public function test_hoy_del_sistema_coincide_con_el_dia_de_negocio_despues_de_las_18(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 19:30:00', 'America/El_Salvador'));

        $this->assertSame('2026-10-03', now()->toDateString());
        $this->assertSame(HoraNegocio::fechaHoy(), today()->toDateString());
    }

    /**
     * Una marcación hecha a las 19:30 locales se lee como 19:30. Si el instante se
     * guardara en UTC con la aplicación en hora local, Eloquent escribiría «01:30» sin
     * convertir y al leerlo lo tomaría como 01:30 locales: seis horas corrido.
     */
    public function test_la_marcacion_de_asistencia_conserva_su_hora_al_guardarse(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 19:30:00', 'America/El_Salvador'));
        $hora = app(HoraOficial::class);

        $marcacion = AsistenciaMarcacion::factory()->create([
            'marcado_at' => $hora->instante(),
            'fecha_local' => $hora->fechaLocal($hora->instante()),
        ])->refresh();

        $this->assertSame('2026-10-05', $marcacion->fecha_local instanceof Carbon ? $marcacion->fecha_local->toDateString() : (string) $marcacion->fecha_local);
        $this->assertSame('19:30:00', $hora->desglosar($marcacion->marcado_at)['hora']);
        $this->assertSame(now()->getTimestamp(), $marcacion->marcado_at->getTimestamp());
    }
}
