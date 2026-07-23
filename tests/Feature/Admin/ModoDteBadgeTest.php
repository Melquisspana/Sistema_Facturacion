<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Badge del navbar con el modo de operación DTE (paralelo/respaldo/principal) + mocks.
 * Visible SOLO en pantallas de Facturación/DTE (no en el resto del sistema) y SOLO para
 * quienes facturan (administrador/facturación). Solo lectura: no transmite ni muestra
 * secretos, y NO cambia ningún candado ni validación de emisión.
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

    /** Pantalla de Facturación donde el badge SÍ debe verse (la lista de CCF/Facturas). */
    private function pantallaFacturacion(): string
    {
        return route('facturacion.index');
    }

    public function test_administrador_ve_el_badge_paralelo_seguro_en_facturacion(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertSee('PARALELO SEGURO');
    }

    public function test_facturacion_tambien_ve_el_badge_en_facturacion(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertSee('PARALELO SEGURO');
    }

    public function test_el_badge_no_aparece_fuera_de_facturacion(): void
    {
        // Nuevo comportamiento: aunque sea admin, el dashboard (y demás pantallas no DTE)
        // ya NO muestran el badge de modo. Solo cambia dónde se ve; los candados server-side
        // siguen intactos.
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PARALELO SEGURO');
    }

    public function test_consulta_no_ve_el_badge(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertDontSee('PARALELO SEGURO');
    }

    public function test_contador_no_ve_el_badge(): void
    {
        $this->actingAs($this->usuario('contador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertDontSee('PARALELO SEGURO');
    }

    public function test_badge_pasa_a_rojo_solo_si_transmision_real_a_produccion_queda_habilitada(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);

        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertSee('PRINCIPAL LISTO')
            ->assertSee('Producción activa');
    }

    public function test_paralelo_con_via_de_pruebas_no_muestra_alerta_de_produccion(): void
    {
        // Bug reportado: modo paralelo + dry-run + vía de pruebas (apitest) no debe gritar
        // "transmite REALES a Hacienda". Queda ámbar apitest, sin la alerta roja.
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        config()->set('dte.transmision.dry_run', true);
        config()->set('dte.transmision.real_confirmation', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);

        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertSee('PARALELO SEGURO')
            ->assertSee('apitest')
            ->assertDontSee('REALES a Hacienda (PRODUCCIÓN)');
    }

    public function test_chip_de_mock_aparece_cuando_hay_algun_mock_activo(): void
    {
        config()->set('dte.transmision.mock', true);

        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertSee('PRUEBAS / MOCK');
    }

    public function test_sin_mocks_no_aparece_el_chip(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertDontSee('PRUEBAS / MOCK');
    }

    public function test_no_muestra_secretos_en_el_badge(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $this->actingAs($this->usuario('administrador'))
            ->get($this->pantallaFacturacion())
            ->assertOk()
            ->assertDontSee('PASSWORD_SECRETO_X')
            ->assertDontSee('TOKEN_SECRETO_X')
            ->assertDontSee('facturador01');
    }
}
