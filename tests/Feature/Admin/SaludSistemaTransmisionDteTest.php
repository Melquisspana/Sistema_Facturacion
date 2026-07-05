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

    public function test_muestra_alerta_roja_solo_si_transmision_real_a_produccion_queda_posible(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('PRINCIPAL LISTO')
            ->assertSee('REALES a Hacienda (PRODUCCIÓN) ahora mismo');
    }

    public function test_via_de_pruebas_apitest_muestra_ambar_no_alerta_roja(): void
    {
        // Bug reportado: paralelo + dry-run + vía de pruebas apitest NO debe mostrar la
        // alerta roja de producción; muestra el aviso ámbar de apitest.
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        config()->set('dte.transmision.dry_run', true);
        config()->set('dte.transmision.real_confirmation', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('admin.salud-sistema'))
            ->assertOk()
            ->assertSee('PARALELO SEGURO')
            ->assertSee('PRUEBAS (apitest)')
            ->assertDontSee('REALES a Hacienda (PRODUCCIÓN) ahora mismo');
    }

    /** Extrae el texto del banner "Estado general" del panel (robusto ante backups/admins). */
    private function bannerGeneral(User $admin): string
    {
        $html = $this->actingAs($admin)->get(route('admin.salud-sistema'))->assertOk()->getContent();
        preg_match('/text-2xl font-bold">([^<]+)</', (string) $html, $m);

        return trim($m[1] ?? '');
    }

    public function test_la_seccion_no_altera_el_estado_general(): void
    {
        // El estado general se calcula de seguridad/backups/alertas; la transmisión DTE
        // (paralelo o principal) es informativa aparte, igual que la cola de correos. Se
        // compara el banner ANTES/DESPUÉS (no un texto fijo, que depende de backups/admins).
        $admin = $this->usuario('administrador');
        $antes = $this->bannerGeneral($admin);

        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);

        $despues = $this->bannerGeneral($admin);

        $this->assertNotSame('', $antes);
        $this->assertSame($antes, $despues, 'La sección Transmisión DTE no debe alterar el banner general.');
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
