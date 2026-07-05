<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección "Transmisión DTE" del panel Salud del sistema: modo de operación + mocks.
 * Solo lectura (reutiliza evaluarCandados()); no transmite, no muestra secretos, y NO
 * se mezcla con el banner "Estado general" (mismo criterio que la sección "cola").
 */
class SaludSistemaTransmisionDteTest extends TestCase
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

    public function test_panel_muestra_la_seccion_transmision_dte(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Transmisión DTE (modo de operación)')
            ->assertSee('PARALELO SEGURO')
            ->assertSee('Conta Portable sigue siendo el sistema oficial');
    }

    public function test_muestra_los_tres_mocks_apagados_por_defecto(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSeeInOrder(['Firma: apagado', 'Transmisión: apagado', 'Invalidación: apagado']);
    }

    public function test_muestra_mock_activo(): void
    {
        config()->set('dte.firma.mock', true);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('Firma: MOCK');
    }

    public function test_muestra_alerta_roja_si_transmision_real_queda_posible(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('PRINCIPAL LISTO')
            ->assertSee('puede transmitir documentos REALES a Hacienda ahora mismo');
    }

    public function test_la_seccion_no_altera_el_estado_general(): void
    {
        // El estado general se calcula de seguridad/backups/alertas; la transmisión DTE
        // (paralelo o principal) es informativa aparte, igual que la cola de correos.
        $admin = $this->usuario('administrador');

        $general = fn () => $this->actingAs($admin)->get(route('admin.salud-sistema'))
            ->assertOk()->assertSee('Sistema NO listo para producción');

        $general(); // baseline: paralelo

        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);

        $general(); // con transmisión real "posible": el general no cambia
    }

    public function test_solo_administrador_ve_el_panel(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('admin.salud-sistema'))
            ->assertForbidden();
    }

    public function test_no_muestra_secretos(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertDontSee('PASSWORD_SECRETO_X')
            ->assertDontSee('TOKEN_SECRETO_X')
            ->assertDontSee('facturador01');
    }
}
