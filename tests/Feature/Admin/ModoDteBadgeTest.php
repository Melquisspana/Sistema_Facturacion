<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Badge del navbar con el modo de operación DTE (paralelo/respaldo/principal) + mocks.
 * Visible SOLO para quienes facturan (administrador/facturación); solo lectura, no
 * transmite ni muestra secretos. No afecta la generación de borradores ni el piloto.
 */
class ModoDteBadgeTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_administrador_ve_el_badge_paralelo_seguro(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PARALELO SEGURO');
    }

    public function test_facturacion_tambien_ve_el_badge(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PARALELO SEGURO');
    }

    public function test_consulta_no_ve_el_badge(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PARALELO SEGURO');
    }

    public function test_contador_no_ve_el_badge(): void
    {
        $this->actingAs($this->usuario('contador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PARALELO SEGURO');
    }

    public function test_badge_pasa_a_rojo_si_la_transmision_real_queda_habilitada(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PRINCIPAL LISTO')
            ->assertSee('REALES a Hacienda');
    }

    public function test_chip_de_mock_aparece_cuando_hay_algun_mock_activo(): void
    {
        config()->set('dte.transmision.mock', true);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PRUEBAS / MOCK');
    }

    public function test_sin_mocks_no_aparece_el_chip(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PRUEBAS / MOCK');
    }

    public function test_no_muestra_secretos_en_el_badge(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PASSWORD_SECRETO_X')
            ->assertDontSee('TOKEN_SECRETO_X')
            ->assertDontSee('facturador01');
    }
}
