<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
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
 * Listado principal de Facturación: muestra el AMBIENTE OPERATIVO ACTUAL de esta
 * instalación (config('dte.ambiente')) — '00' en desarrollo/APITEST, '01' en el
 * servidor de producción — con TODOS los estados (borrador incluido), para poder
 * seguir trabajando. Nunca mezcla ambos ambientes en la misma vista por defecto.
 *
 * El filtro fijo a SIEMPRE producción real (ambiente '01', sin importar la
 * instalación) es exclusivo del Dashboard/negocio — ver DashboardTest — y de la
 * pantalla de invalidaciones; NO aplica a este listado operativo.
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

    private function dte(string $ambiente, string $estado, ?string $numeroControl, string $clienteNombre): Dte
    {
        return Dte::create([
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_dte' => '03',
            'estado' => $estado,
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
        return $this->dte('01', EstadoDte::Aceptado->value, 'DTE-03-M001P001-000000000001078', 'Cliente Producción SA');
    }

    private function prueba(): Dte
    {
        return $this->dte('00', EstadoDte::Aceptado->value, 'DTE-03-M001P001-000000000000013', 'Cliente Prueba SA');
    }

    // --- Listado principal = ambiente operativo ACTUAL de la instalación ---

    public function test_en_desarrollo_el_listado_muestra_ambiente_00_y_oculta_01(): void
    {
        config(['dte.ambiente' => '00']);
        $dev = $this->prueba();
        $prod = $this->produccion();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee($dev->numero_control)
            ->assertDontSee($prod->numero_control)
            ->assertSee('Pruebas'); // etiqueta de ambiente del listado
    }

    public function test_en_produccion_el_listado_muestra_ambiente_01_y_oculta_00(): void
    {
        config(['dte.ambiente' => '01']);
        $dev = $this->prueba();
        $prod = $this->produccion();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee($prod->numero_control)
            ->assertDontSee($dev->numero_control)
            ->assertSee('Producción'); // etiqueta de ambiente del listado
    }

    public function test_borrador_del_ambiente_actual_aparece_y_puede_editarse(): void
    {
        // Reproduce la regresión: un CCF recién creado (borrador, sin número de control
        // todavía) en el ambiente activo de la instalación DEBE verse y poder editarse.
        config(['dte.ambiente' => '00']);
        $borrador = $this->dte('00', EstadoDte::Borrador->value, null, 'Cliente Borrador Dev SA');

        $resp = $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.index'))->assertOk();

        $resp->assertSee('Cliente Borrador Dev SA');
        $resp->assertSee(route('facturacion.edit', $borrador), false);
    }

    public function test_generado_del_ambiente_actual_aparece_en_desarrollo(): void
    {
        config(['dte.ambiente' => '00']);
        $generado = $this->dte('00', EstadoDte::Generado->value, 'DTE-03-M001P002-000000000000004', 'Cliente Generado Dev SA');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee($generado->numero_control);
    }

    public function test_borrador_ambiente_01_aparece_cuando_la_instalacion_esta_en_produccion(): void
    {
        config(['dte.ambiente' => '01']);
        $borrador = $this->dte('01', EstadoDte::Borrador->value, null, 'Cliente Borrador Prod SA');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Cliente Borrador Prod SA');
    }

    public function test_todos_incluye_todos_los_estados_del_ambiente_actual(): void
    {
        config(['dte.ambiente' => '00']);
        $borrador = $this->dte('00', EstadoDte::Borrador->value, null, 'Cliente Todos Borrador SA');
        $generado = $this->dte('00', EstadoDte::Generado->value, 'DTE-03-M001P002-000000000000010', 'Cliente Todos Generado SA');
        $aceptado = $this->dte('00', EstadoDte::Aceptado->value, 'DTE-03-M001P002-000000000000011', 'Cliente Todos Aceptado SA');
        $rechazado = $this->dte('00', EstadoDte::Rechazado->value, 'DTE-03-M001P002-000000000000012', 'Cliente Todos Rechazado SA');
        $invalidado = $this->dte('00', EstadoDte::Invalidado->value, 'DTE-03-M001P002-000000000000013', 'Cliente Todos Invalidado SA');

        // Sin filtro de estado ("Todos"): deben aparecer los 5, del ambiente actual.
        $resp = $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.index'))->assertOk();

        $resp->assertSee('Cliente Todos Borrador SA');
        $resp->assertSee($generado->numero_control);
        $resp->assertSee($aceptado->numero_control);
        $resp->assertSee($rechazado->numero_control);
        $resp->assertSee($invalidado->numero_control);
    }

    // --- Acceso a pruebas ESCONDIDO en Auditoría (sin cambios) ---

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
