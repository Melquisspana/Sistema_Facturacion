<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contador visible de trabajos fallidos en el navbar (badge junto a "Salud del sistema",
 * solo administradores) + su conteo en el panel Salud del sistema. Solo lectura de
 * failed_jobs: no reintenta ni borra. SÍ afecta el estado general del panel (vía
 * DiagnosticoSistemaService): failed_jobs > 0 es siempre un crítico real.
 */
class JobsFallidosBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const MARCA = 'trabajos en cola fallidos';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Inserta N filas en failed_jobs (estructura estándar). */
    private function fallidos(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{"displayName":"App\\\\Jobs\\\\EnviarDteCorreo"}',
                'exception' => 'SMTP caído',
                'failed_at' => now(),
            ]);
        }
    }

    // --- Navbar ---

    public function test_admin_ve_el_contador_con_jobs_fallidos(): void
    {
        $this->fallidos(3);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('3 '.self::MARCA); // badge con la cantidad
    }

    public function test_sin_fallidos_no_muestra_badge(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::MARCA);
    }

    public function test_rol_no_admin_no_ve_el_badge_aunque_haya_fallidos(): void
    {
        $this->fallidos(2);

        // facturación no ve el enlace de Salud del sistema ni el badge.
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(self::MARCA);
    }

    // --- Panel Salud del sistema ---

    public function test_panel_salud_muestra_el_conteo_de_fallidos(): void
    {
        $this->fallidos(4);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Trabajos fallidos')
            ->assertSee('Hay 4 trabajo(s) fallido(s)');
    }

    public function test_los_fallidos_ahora_hacen_critico_el_estado_general(): void
    {
        // Cambio de diseño deliberado (DiagnosticoSistemaService): failed_jobs > 0 es
        // SIEMPRE un disparador de "atención inmediata" real, ya no un dato meramente
        // informativo de la cola. Se aísla de APP_DEBUG (advertencia real aparte) para
        // que la prueba sea sobre esto específicamente.
        config(['app.debug' => false]);
        $admin = $this->usuario('administrador');

        $banner = function () use ($admin): string {
            $html = $this->actingAs($admin)->get(route('admin.salud-sistema'))->assertOk()->getContent();
            preg_match('/text-2xl font-bold">([^<]+)</', (string) $html, $m);

            return trim($m[1] ?? '');
        };

        $antes = $banner();      // baseline (0 fallidos)
        $this->fallidos(5);
        $despues = $banner();    // con fallidos: ahora SÍ debe reflejar un problema real

        $this->assertNotSame('', $antes);
        $this->assertStringContainsString('Atención inmediata', $despues);
    }
}
