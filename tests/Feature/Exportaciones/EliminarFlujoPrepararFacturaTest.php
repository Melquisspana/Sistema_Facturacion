<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\User;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Confirma la eliminación completa del flujo viejo "Preparar factura de
 * exportación" (nunca se usó) y que solo queda el flujo nuevo: Crear FEX /
 * Abrir FEX, mutuamente excluyentes.
 */
class EliminarFlujoPrepararFacturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CatalogosMhSeeder::class);
        $this->seed(\Database\Seeders\CatalogosMhTablaSeeder::class);
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $empresa = \App\Models\Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = \App\Models\Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = \App\Models\PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        \App\Models\Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function item(Exportacion $e): void
    {
        $e->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);
    }

    public function test_preparar_factura_de_exportacion_no_aparece_en_ningun_estado(): void
    {
        // A: sin cliente vinculado
        $expoA = ExportacionCliente::create(['nombre' => 'Cliente A', 'activo' => true]);
        $listaA = Exportacion::create(['exportacion_cliente_id' => $expoA->id, 'cliente_nombre' => 'Cliente A', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);

        // B: vinculado, sin líneas
        $clienteDteB = Cliente::factory()->exportacion()->create();
        $expoB = ExportacionCliente::create(['nombre' => 'Cliente B', 'cliente_id' => $clienteDteB->id, 'activo' => true]);
        $listaB = Exportacion::create(['exportacion_cliente_id' => $expoB->id, 'cliente_nombre' => 'Cliente B', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);

        // C: vinculado, con líneas, sin FEX
        $clienteDteC = Cliente::factory()->exportacion()->create();
        $expoC = ExportacionCliente::create(['nombre' => 'Cliente C', 'cliente_id' => $clienteDteC->id, 'activo' => true]);
        $listaC = Exportacion::create(['exportacion_cliente_id' => $expoC->id, 'cliente_nombre' => 'Cliente C', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);
        $this->item($listaC);

        // D: con FEX
        $clienteDteD = Cliente::factory()->exportacion()->create();
        $expoD = ExportacionCliente::create(['nombre' => 'Cliente D', 'cliente_id' => $clienteDteD->id, 'activo' => true]);
        $listaD = Exportacion::create(['exportacion_cliente_id' => $expoD->id, 'cliente_nombre' => 'Cliente D', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);
        $this->item($listaD);
        app(CrearFexDesdeExportacionService::class)->crear($listaD);

        $usuario = $this->usuario();
        foreach ([$listaA, $listaB, $listaC, $listaD->fresh()] as $lista) {
            $this->actingAs($usuario)
                ->get(route('exportaciones.show', $lista))
                ->assertOk()
                ->assertDontSee('Preparar factura de exportación');
        }
    }

    public function test_lista_sin_fex_muestra_solamente_crear_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $expo = ExportacionCliente::create(['nombre' => 'Cliente E', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create(['exportacion_cliente_id' => $expo->id, 'cliente_nombre' => 'Cliente E', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Crear factura de exportación')
            ->assertDontSee('Abrir factura de exportación')
            ->assertDontSee('Preparar factura de exportación');
    }

    public function test_lista_con_fex_muestra_solamente_abrir_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $expo = ExportacionCliente::create(['nombre' => 'Cliente F', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create(['exportacion_cliente_id' => $expo->id, 'cliente_nombre' => 'Cliente F', 'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada']);
        $this->item($lista);
        app(CrearFexDesdeExportacionService::class)->crear($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista->fresh()))
            ->assertOk()
            ->assertSee('Abrir factura de exportación')
            ->assertDontSee('Crear factura de exportación')
            ->assertDontSee('Preparar factura de exportación');
    }

    public function test_no_existe_ruta_huerfana_preparar_factura(): void
    {
        $this->expectException(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
        route('exportaciones.preparar-factura', ['exportacion' => 1]);
    }

    public function test_no_existe_ruta_huerfana_preparar_factura_excel(): void
    {
        $this->expectException(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
        route('exportaciones.preparar-factura.excel', ['exportacion' => 1]);
    }

    public function test_clase_controlador_ya_no_tiene_los_metodos_viejos(): void
    {
        $this->assertFalse(method_exists(\App\Http\Controllers\Exportaciones\ExportacionController::class, 'prepararFactura'));
        $this->assertFalse(method_exists(\App\Http\Controllers\Exportaciones\ExportacionController::class, 'excelFactura'));
        $this->assertFalse(class_exists(\App\Services\Exportaciones\FacturaExportacionExcel::class));
    }
}
