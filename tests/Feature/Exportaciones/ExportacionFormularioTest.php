<?php

namespace Tests\Feature\Exportaciones;

use App\Models\ExportacionCliente;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El formulario de crear/editar lista de empaque renderiza con el buscador de
 * productos (combobox) y con los datos del catálogo/cliente para Alpine.
 */
class ExportacionFormularioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function datosBase(): array
    {
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Caja de caramelo naranja',
            'nombre_en' => 'Orange candy box - 144 units',
            'unidad' => 'Bolsa 12X12',
            'unidades_por_caja' => 144,
            'gramos_por_unidad' => 85,
            'onzas_por_unidad' => 3.00,
            'precio_caja' => 136.80,
            'peso_neto_caja_kg' => 13.00,
            'peso_bruto_caja_kg' => 14.00,
            'peso_neto_caja_lb' => 28.66,
            'peso_bruto_caja_lb' => 30.86,
            'activo' => true,
        ]);
        $cliente = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'activo' => true]);
        $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 150.00,
            'activo' => true,
        ]);

        return [$cliente, $producto];
    }

    public function test_crear_renderiza_el_buscador_con_datos_de_productos_y_clientes(): void
    {
        [$cliente, $producto] = $this->datosBase();

        $respuesta = $this->actingAs($this->usuario())->get(route('exportaciones.create'));

        $respuesta->assertOk();
        // El combobox reemplaza al select: placeholder de búsqueda presente.
        $respuesta->assertSee('Escribí para buscar producto', false);
        // Datos que Alpine usa para filtrar: nombre, empaque y precio del cliente.
        $respuesta->assertSee('Caja de caramelo naranja');
        $respuesta->assertSee('Bolsa 12X12');
        $respuesta->assertSee($cliente->nombre);
        // La lista de precios del cliente viaja al componente (precio específico 150).
        $respuesta->assertSee((string) $producto->id, false);
        $respuesta->assertSee('150', false);
    }

    public function test_editar_renderiza_el_buscador_y_el_snapshot_de_items_existentes(): void
    {
        [$cliente, $producto] = $this->datosBase();

        $this->actingAs($this->usuario())->post(route('exportaciones.store'), [
            'exportacion_cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'EXPORTADOR',
            'fecha' => '2026-07-09',
            'items' => [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 3]],
        ]);
        $exportacion = \App\Models\Exportacion::firstOrFail();

        $respuesta = $this->actingAs($this->usuario())->get(route('exportaciones.edit', $exportacion));

        $respuesta->assertOk();
        // El buscador está disponible para agregar filas nuevas…
        $respuesta->assertSee('Escribí para buscar producto', false);
        // …y el item existente viaja con su snapshot (precio del cliente al crear).
        $respuesta->assertSee('Caja de caramelo naranja');
        $respuesta->assertSee('150', false);
    }
}
