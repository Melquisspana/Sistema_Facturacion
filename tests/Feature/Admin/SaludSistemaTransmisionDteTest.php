<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección "Transmisión DTE" del panel Salud del sistema: modo de operación + mocks.
 * Solo lectura (reutiliza evaluarCandados()); no transmite, no muestra secretos. Su
 * color SÍ se combina en el banner "Estado general" (vía DiagnosticoSistemaService,
 * compartido con el Dashboard): si la transmisión real a producción queda posible
 * ahora mismo, eso es un "atención inmediata" real.
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
            ->assertSee('Modo paralelo seguro: este sistema no transmite producción');
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

    public function test_transmision_real_a_produccion_posible_hace_critico_el_estado_general(): void
    {
        // Cambio de diseño deliberado (DiagnosticoSistemaService, reutilizado también
        // por el Dashboard): la transmisión SÍ se evalúa junto con el resto de señales
        // para el banner general — si el sistema puede transmitir REAL a producción
        // ahora mismo, eso es "atención inmediata" real, no un dato meramente informativo.
        config(['app.debug' => false]);
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        $admin = $this->usuario('administrador');

        $antes = $this->bannerGeneral($admin);
        $this->assertStringNotContainsString('Atención inmediata', $antes);

        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);

        $despues = $this->bannerGeneral($admin);
        $this->assertStringContainsString('Atención inmediata', $despues);
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
