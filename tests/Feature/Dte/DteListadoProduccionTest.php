<?php

namespace Tests\Feature\Dte;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Listado principal LIMPIO: muestra SOLO documentos de producción real (ambiente 01,
 * desde el CCF 1078). Las pruebas/piloto/simulación (ambiente 00) quedan fuera del
 * listado principal y su acceso vive ESCONDIDO en el panel de Auditoría (admin/contador).
 */
class DteListadoProduccionTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function dte(string $ambiente, string $numeroControl, string $clienteNombre): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => $ambiente,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => $clienteNombre])->id,
            'numero_control' => $numeroControl,
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 100.0,
        ]);
    }

    private function produccion(): Dte
    {
        return $this->dte('01', 'DTE-03-M001P001-000000000001078', 'Cliente Producción SA');
    }

    private function prueba(): Dte
    {
        return $this->dte('00', 'DTE-03-M001P001-000000000000013', 'Cliente Prueba SA');
    }

    // --- Listado principal = solo producción ---

    public function test_listado_principal_muestra_produccion_y_oculta_pruebas(): void
    {
        $this->produccion();
        $this->prueba();

        // Se asierta por número de control (solo aparece en filas de la tabla; el nombre de
        // cliente NO sirve porque también sale en el <select> de filtro de clientes).
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('DTE-03-M001P001-000000000001078') // producción visible (1078)
            ->assertDontSee('DTE-03-M001P001-000000000000013') // prueba oculta
            ->assertSee('solo documentos de'); // nota "Mostrando solo documentos de producción"
    }

    public function test_listado_principal_no_ofrece_ver_pruebas(): void
    {
        $this->prueba();

        // A propósito NO hay botón/enlace para ver pruebas en el listado normal.
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertDontSee('documentos de prueba', false)
            ->assertDontSee(route('auditoria.documentos_prueba'), false);
    }

    // --- Acceso a pruebas ESCONDIDO en Auditoría ---

    public function test_auditoria_muestra_boton_a_documentos_de_prueba(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Ver documentos de prueba/simulación')
            ->assertSee(route('auditoria.documentos_prueba'), false);
    }

    public function test_listado_de_pruebas_muestra_pruebas_y_oculta_produccion(): void
    {
        $this->produccion();
        $this->prueba();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('auditoria.documentos_prueba'))
            ->assertOk()
            ->assertSee('DTE-03-M001P001-000000000000013') // prueba visible
            ->assertSee('Cliente Prueba SA')
            ->assertDontSee('DTE-03-M001P001-000000000001078') // producción NO acá
            ->assertDontSee('Cliente Producción SA');
    }

    public function test_contador_accede_al_listado_de_pruebas(): void
    {
        $this->prueba();

        $this->actingAs($this->usuario('contador'))
            ->get(route('auditoria.documentos_prueba'))
            ->assertOk()
            ->assertSee('Cliente Prueba SA');
    }

    public function test_facturacion_no_accede_al_listado_de_pruebas(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('auditoria.documentos_prueba'))
            ->assertForbidden();
    }

    public function test_consulta_no_accede_al_listado_de_pruebas(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('auditoria.documentos_prueba'))
            ->assertForbidden();
    }
}
