<?php

namespace Tests\Feature\Admin;

use App\Support\WorkerHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Heartbeat del worker de colas: cálculo de estado (activo/inactivo/sin datos) y su
 * indicador en el panel "Salud del sistema". Solo observación; no toca la cola.
 */
class WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'worker.heartbeat.ts';

    protected function setUp(): void
    {
        parent::setUp();
        WorkerHeartbeat::olvidar();
        Cache::flush();
    }

    // --- Cálculo de estado ---

    public function test_sin_datos_cuando_nunca_hubo_pulso(): void
    {
        $estado = WorkerHeartbeat::estado();

        $this->assertSame('sin_datos', $estado['estado']);
        $this->assertNull($estado['ultimo']);
    }

    public function test_activo_con_pulso_reciente(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), now()->addDay());

        $estado = WorkerHeartbeat::estado();

        $this->assertSame('activo', $estado['estado']);
        $this->assertNotNull($estado['ultimo']);
    }

    public function test_inactivo_con_pulso_viejo(): void
    {
        Cache::put(self::KEY, now()->subMinutes(10)->getTimestamp(), now()->addDay());

        $estado = WorkerHeartbeat::estado();

        $this->assertSame('inactivo', $estado['estado']);
    }

    public function test_justo_en_el_umbral_sigue_activo_y_pasado_es_inactivo(): void
    {
        Cache::put(self::KEY, now()->subSeconds(119)->getTimestamp(), now()->addDay());
        $this->assertSame('activo', WorkerHeartbeat::estado()['estado']);

        Cache::put(self::KEY, now()->subSeconds(121)->getTimestamp(), now()->addDay());
        $this->assertSame('inactivo', WorkerHeartbeat::estado()['estado']);
    }

    public function test_pulse_marca_el_worker_activo(): void
    {
        $this->assertSame('sin_datos', WorkerHeartbeat::estado()['estado']);

        WorkerHeartbeat::pulse();

        $this->assertSame('activo', WorkerHeartbeat::estado()['estado']);
        $this->assertNotNull(Cache::get(self::KEY));
    }

    // --- Panel Salud del sistema ---

    private function admin(): User
    {
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create()->assignRole('administrador');
    }

    public function test_panel_muestra_worker_activo(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), now()->addDay());

        $this->actingAs($this->admin())
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Cola de correos (worker)')
            ->assertSee('Worker activo');
    }

    public function test_panel_muestra_worker_detenido_con_pulso_viejo(): void
    {
        Cache::put(self::KEY, now()->subMinutes(10)->getTimestamp(), now()->addDay());

        $this->actingAs($this->admin())
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Worker posiblemente detenido');
    }

    public function test_panel_muestra_sin_datos_si_no_hubo_pulso(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Sin datos todavía');
    }
}
