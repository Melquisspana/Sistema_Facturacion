<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exportaciones / Lista de Empaque: catálogo maestro + lista de precios por
 * cliente. El precio del item sale PRIMERO de exportacion_cliente_productos;
 * si no hay, cae al precio base del catálogo avisando; sin ninguno, se rechaza.
 * Módulo administrativo: NO toca emisión DTE.
 */
class ExportacionPrecioClienteTest extends TestCase
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

    private function producto(array $extra = []): ExportacionProducto
    {
        return ExportacionProducto::create($extra + [
            'nombre_es' => 'Caja de semilla de marañón',
            'nombre_en' => 'Cashew seed box - 216 units',
            'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 216,
            'gramos_por_unidad' => 45,
            'onzas_por_unidad' => 1.59,
            'precio_caja' => 259.20,
            'peso_neto_caja_kg' => 10.30,
            'peso_bruto_caja_kg' => 11.30,
            'peso_neto_caja_lb' => 22.71,
            'peso_bruto_caja_lb' => 24.91,
            'activo' => true,
        ]);
    }

    private function cliente(array $extra = []): ExportacionCliente
    {
        return ExportacionCliente::create($extra + [
            'nombre' => 'CAROLINAS WHOLESALE LLC',
            'direccion' => '11235 SOMERSET, BELTSVILLE, MD 20705 EEUU',
            'fda_reg_number' => '12015435846',
            'activo' => true,
        ]);
    }

    private function payload(ExportacionCliente $cliente, array $items): array
    {
        return [
            'exportacion_cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'cliente_direccion' => $cliente->direccion,
            'exportador_nombre' => 'ELSA FIDELINA HERNANDEZ DE ESPAÑA',
            'fecha' => '2026-07-08',
            'fda_reg_number' => $cliente->fda_reg_number,
            'items' => $items,
        ];
    }

    public function test_store_usa_el_precio_especifico_del_cliente(): void
    {
        $producto = $this->producto();
        $cliente = $this->cliente();
        $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 300.00,
            'activo' => true,
        ]);

        $respuesta = $this->actingAs($this->usuario())->post(route('exportaciones.store'),
            $this->payload($cliente, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 4]]));

        $respuesta->assertRedirect();
        $item = ExportacionItem::firstOrFail();
        // Precio del CLIENTE (no el base 259.20) y snapshot completo copiado.
        $this->assertSame('300.00', $item->precio_caja);
        $this->assertSame('Caja de semilla de marañón', $item->nombre_es);
        $this->assertSame(216, $item->unidades_por_caja);
        $this->assertSame('10.30', $item->peso_neto_caja_kg);
        // Con precio propio del cliente no hay aviso de fallback.
        $this->assertNull(session('aviso_precios'));
    }

    public function test_store_sin_precio_de_cliente_cae_al_base_y_avisa(): void
    {
        $producto = $this->producto();
        $cliente = $this->cliente(); // sin lista de precios

        $respuesta = $this->actingAs($this->usuario())->post(route('exportaciones.store'),
            $this->payload($cliente, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 2]]));

        $respuesta->assertRedirect();
        $this->assertSame('259.20', ExportacionItem::firstOrFail()->precio_caja);
        $this->assertStringContainsString('PRECIO BASE', session('aviso_precios'));
        $this->assertStringContainsString($producto->nombre_es, session('aviso_precios'));
    }

    public function test_asignacion_inactiva_no_cuenta_como_precio_del_cliente(): void
    {
        $producto = $this->producto();
        $cliente = $this->cliente();
        $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 300.00,
            'activo' => false, // deshabilitado para el cliente
        ]);

        $this->actingAs($this->usuario())->post(route('exportaciones.store'),
            $this->payload($cliente, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 1]]));

        // Cae al base con aviso, porque la asignación está inactiva.
        $this->assertSame('259.20', ExportacionItem::firstOrFail()->precio_caja);
        $this->assertNotNull(session('aviso_precios'));
    }

    public function test_store_rechaza_producto_sin_ningun_precio(): void
    {
        $producto = $this->producto(['precio_caja' => null]); // sin precio base
        $cliente = $this->cliente(); // y sin precio de cliente

        $respuesta = $this->actingAs($this->usuario())->post(route('exportaciones.store'),
            $this->payload($cliente, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 1]]));

        $respuesta->assertSessionHasErrors('items');
        $this->assertSame(0, Exportacion::count());
        $this->assertSame(0, ExportacionItem::count());
    }

    public function test_update_conserva_el_snapshot_de_items_existentes(): void
    {
        $producto = $this->producto();
        $otro = $this->producto(['nombre_es' => 'Caja de espumillas', 'nombre_en' => 'Espumillas box']);
        $cliente = $this->cliente();
        $asignacion = $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 300.00,
            'activo' => true,
        ]);
        $cliente->productos()->create([
            'exportacion_producto_id' => $otro->id,
            'precio_caja' => 40.00,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())->post(route('exportaciones.store'),
            $this->payload($cliente, [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 4]]));
        $exportacion = Exportacion::firstOrFail();
        $itemViejo = $exportacion->items()->firstOrFail();

        // El precio del cliente cambia DESPUÉS de creada la exportación.
        $asignacion->update(['precio_caja' => 350.00]);

        $this->actingAs($this->usuario())->put(route('exportaciones.update', $exportacion),
            $this->payload($cliente, [
                ['id' => $itemViejo->id, 'cantidad_cajas' => 7],
                ['exportacion_producto_id' => $otro->id, 'cantidad_cajas' => 2],
            ]));

        $itemViejo->refresh();
        // Item existente: snapshot intacto (precio viejo), solo cambió la cantidad.
        $this->assertSame('300.00', $itemViejo->precio_caja);
        $this->assertSame(7, $itemViejo->cantidad_cajas);
        // Item nuevo: precio del cliente vigente hoy.
        $nuevo = $exportacion->items()->where('exportacion_producto_id', $otro->id)->firstOrFail();
        $this->assertSame('40.00', $nuevo->precio_caja);
    }

    public function test_no_se_puede_asignar_dos_veces_el_mismo_producto_al_cliente(): void
    {
        $producto = $this->producto();
        $cliente = $this->cliente();
        $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 300.00,
            'activo' => true,
        ]);

        $respuesta = $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.store', $cliente),
            ['exportacion_producto_id' => $producto->id, 'precio_caja' => 310.00],
        );

        $respuesta->assertSessionHasErrors('exportacion_producto_id');
        $this->assertSame(1, $cliente->productos()->count());
    }

    public function test_rol_consulta_no_accede_al_modulo(): void
    {
        $usuario = User::factory()->create()->assignRole('consulta');

        $this->actingAs($usuario)->get(route('exportaciones.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('exportaciones.clientes.index'))->assertForbidden();
    }
}
