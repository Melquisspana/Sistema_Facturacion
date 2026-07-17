<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Editor de borrador FEX: origen desde la Lista de Empaque, etiquetas Cajas /
 * Precio por caja, y edición de líneas libres (sin producto de catálogo).
 * No cambia el editor de CCF/NC/Factura.
 */
class DteExportacionEditorLineaLibreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        foreach (['administrador', 'facturacion', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function fexDesdeLista(): Dte
    {
        $empresa = \App\Models\Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id,
            'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-17',
            'estado' => 'aprobada',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        return app(CrearFexDesdeExportacionService::class)->crear($lista);
    }

    public function test_editor_fex_muestra_origen_lista_de_empaque(): void
    {
        $dte = $this->fexDesdeLista();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Lista de Empaque')
            ->assertSee(route('exportaciones.show', $dte->exportacionOrigen), false);
    }

    public function test_editor_fex_muestra_etiqueta_cajas_y_precio_por_caja(): void
    {
        $dte = $this->fexDesdeLista();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Cajas')
            ->assertSee('Precio por caja')
            ->assertSee('/ caja', false);
    }

    public function test_editor_fex_permite_agregar_linea_libre(): void
    {
        $dte = $this->fexDesdeLista();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.lineas-libres.store', $dte), [
                'descripcion' => 'Dulce de nance',
                'unidad_codigo' => '99',
                'cantidad' => 5,
                'precio_unitario' => 20,
            ])
            ->assertRedirect();

        $this->assertSame(2, $dte->lineas()->count());
        $this->assertDatabaseHas('dte_lineas', ['dte_id' => $dte->id, 'descripcion' => 'Dulce de nance', 'producto_id' => null]);
    }

    public function test_editor_fex_permite_editar_descripcion_y_precio_de_linea_libre(): void
    {
        $dte = $this->fexDesdeLista();
        $linea = $dte->lineas->first();

        $this->actingAs($this->usuario())
            ->patch(route('facturacion.lineas.update', [$dte, $linea]), [
                'descripcion' => 'Canillitas 85 g (corregido)',
                'precio_unitario' => 19.50,
                'cantidad' => 10,
            ])
            ->assertRedirect();

        $linea->refresh();
        $this->assertSame('Canillitas 85 g (corregido)', $linea->descripcion);
        $this->assertSame('19.500000', $linea->precio_unitario);
    }

    public function test_editar_linea_libre_no_modifica_la_lista_de_empaque(): void
    {
        $dte = $this->fexDesdeLista();
        $linea = $dte->lineas->first();
        $item = $dte->exportacionOrigen->items->first();

        app(DteBorradorService::class)->actualizarLinea($linea, ['descripcion' => 'Cambiado solo en la FEX', 'precio_unitario' => 99, 'cantidad' => (int) $linea->cantidad]);

        $item->refresh();
        $this->assertSame('Canillitas 85 g', $item->nombre_es);
        $this->assertEquals(18.00, (float) $item->precio_caja);
    }

    // ---------- Regresión: CCF no cambia ----------

    public function test_ccf_no_muestra_etiquetas_ni_formulario_de_linea_libre(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $empresa = \App\Models\Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $dte = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('Agregar línea libre')
            ->assertDontSee('Precio por caja')
            ->assertDontSee('Lista de Empaque');
    }
}
