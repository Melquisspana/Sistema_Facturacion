<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Cliente;
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
 * Fase 2 del módulo Exportaciones: precio por unidad calculado. La copia de precios
 * entre clientes, la asignación del catálogo completo y el precio $0 confirmado eran
 * rutas de la ficha del cliente que ningún botón usaba; se retiraron (409) y acá se
 * prueba que ya no cambian precios ni listas. NO toca emisión DTE.
 */
class ExportacionPreciosFase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contabilidad', 'jefatura'] as $rol) {
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
        static $n = 0;
        $n++;

        return ExportacionProducto::create($extra + [
            'nombre_es' => "Producto de exportación {$n}",
            'nombre_en' => "Export product {$n}",
            'unidad' => 'Bolsa 12X18',
            'unidades_por_caja' => 216,
            'gramos_por_unidad' => 45,
            'onzas_por_unidad' => 1.59,
            'precio_caja' => 270.00,
            'peso_neto_caja_kg' => 10.30,
            'peso_bruto_caja_kg' => 11.30,
            'peso_neto_caja_lb' => 22.71,
            'peso_bruto_caja_lb' => 24.91,
            'activo' => true,
        ]);
    }

    /** Perfil de exportación colgando de un cliente REAL del directorio. */
    private function cliente(string $nombre): ExportacionCliente
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);

        return ExportacionCliente::create([
            'cliente_id' => $clienteDte->id, 'nombre' => $nombre, 'activo' => true,
        ]);
    }

    // ---------- 1) precio por unidad ----------

    public function test_precio_por_unidad_calculado_en_catalogo_cliente_e_item(): void
    {
        // $270.00 / 216 = $1.25 (el formato pedido).
        $producto = $this->producto(['precio_caja' => 270.00, 'unidades_por_caja' => 216]);
        $this->assertSame(1.25, $producto->precioPorUnidad());

        // Precio del CLIENTE distinto del base: la unidad sale del precio del cliente.
        $cliente = $this->cliente('CLIENTE A');
        $asignacion = $cliente->productos()->create([
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 324.00,
            'activo' => true,
        ]);
        $this->assertSame(1.5, $asignacion->precioPorUnidad());

        // Snapshot del item: usa el precio congelado del item, no el del catálogo.
        $exportacion = Exportacion::create([
            'exportacion_cliente_id' => $cliente->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'EXPORTADOR',
            'fecha' => '2026-07-08',
        ]);
        $item = ExportacionItem::create([
            'exportacion_id' => $exportacion->id,
            'exportacion_producto_id' => $producto->id,
            'cantidad_cajas' => 2,
            'precio_caja' => 324.00,
        ] + $producto->datosSnapshot());
        $this->assertSame(1.5, $item->precioPorUnidad());

        // Sin precio base no hay precio por unidad (no revienta).
        $sinPrecio = $this->producto(['precio_caja' => null]);
        $this->assertNull($sinPrecio->precioPorUnidad());
    }

    // ---------- 3, 4 y 5) copiar, asignar el catálogo y precio $0: retiradas ----------

    public function test_copiar_asignar_catalogo_y_precio_cero_ya_no_cambian_precios_ni_listas(): void
    {
        $producto = $this->producto();
        $soloOrigen = $this->producto();
        $this->producto(['precio_caja' => 270.00]); // uno del catálogo que el destino no tiene
        $origen = $this->cliente('ORIGEN');
        $destino = $this->cliente('DESTINO');
        $origen->productos()->create(['exportacion_producto_id' => $producto->id, 'precio_caja' => 100.00, 'activo' => true]);
        $origen->productos()->create(['exportacion_producto_id' => $soloOrigen->id, 'precio_caja' => 200.00, 'activo' => true]);
        $asignacion = $destino->productos()->create(['exportacion_producto_id' => $producto->id, 'precio_caja' => 150.00, 'activo' => true]);

        // Lista del destino con el precio de aquel momento (150).
        $this->actingAs($this->usuario())->post(route('facturacion.listas.store'), [
            'exportacion_cliente_id' => $destino->id,
            'cliente_nombre' => $destino->nombre,
            'exportador_nombre' => 'EXPORTADOR',
            'fecha' => '2026-07-08',
            'items' => [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 3]],
        ]);
        $item = ExportacionItem::firstOrFail();
        $this->assertSame('150.00', $item->precio_caja);

        $cliente = $destino->cliente_id;
        foreach ([
            ['post', route('clientes.exportacion.productos.copiar', $cliente), ['origen_id' => $origen->id, 'modo' => 'conservar']],
            ['post', route('clientes.exportacion.productos.copiar', $cliente), ['origen_id' => $origen->id, 'modo' => 'sobrescribir']],
            ['post', route('clientes.exportacion.productos.copiar', $cliente), ['origen_id' => $destino->id, 'modo' => 'conservar']],
            ['post', route('clientes.exportacion.productos.asignar-catalogo', $cliente), []],
            ['post', route('clientes.exportacion.productos.store', $cliente), ['exportacion_producto_id' => $soloOrigen->id, 'precio_caja' => 0, 'confirmar_cero' => 1]],
            ['patch', route('clientes.exportacion.productos.update', [$cliente, $asignacion]), ['precio_caja' => 0, 'confirmar_cero' => 1]],
            ['patch', route('clientes.exportacion.productos.update', [$cliente, $asignacion]), ['precio_caja' => -5]],
        ] as [$metodo, $url, $datos]) {
            $this->actingAs($this->usuario())->{$metodo}($url, $datos)->assertStatus(409);
        }

        // El destino sigue con su único precio, el origen con los suyos y la lista con su copia.
        $this->assertSame(1, $destino->productos()->count());
        $this->assertSame('150.00', $asignacion->refresh()->precio_caja);
        $this->assertSame(2, $origen->productos()->count());
        $this->assertSame('150.00', $item->refresh()->precio_caja);
        $this->assertSame(0.69, $item->precioPorUnidad());
    }
}
