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
 * Fase 2 del módulo Exportaciones: precio por unidad calculado, copia de
 * precios entre clientes (conservar/sobrescribir), asignación de catálogo sin
 * ceros silenciosos y confirmación de precio $0. NO toca emisión DTE.
 */
class ExportacionPreciosFase2Test extends TestCase
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

    private function cliente(string $nombre): ExportacionCliente
    {
        return ExportacionCliente::create(['nombre' => $nombre, 'activo' => true]);
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

    // ---------- 3) copiar precios entre clientes ----------

    public function test_copiar_precios_sin_sobrescribir_existentes(): void
    {
        $comun = $this->producto();
        $soloOrigen = $this->producto();
        $origen = $this->cliente('ORIGEN');
        $destino = $this->cliente('DESTINO');
        $origen->productos()->create(['exportacion_producto_id' => $comun->id, 'precio_caja' => 100.00, 'activo' => true]);
        $origen->productos()->create(['exportacion_producto_id' => $soloOrigen->id, 'precio_caja' => 200.00, 'activo' => true]);
        // Una asignación INACTIVA en el origen no debe copiarse.
        $inactivo = $this->producto();
        $origen->productos()->create(['exportacion_producto_id' => $inactivo->id, 'precio_caja' => 999.00, 'activo' => false]);
        // El destino ya tiene el producto común con SU precio.
        $destino->productos()->create(['exportacion_producto_id' => $comun->id, 'precio_caja' => 150.00, 'activo' => true]);

        $respuesta = $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.copiar', $destino),
            ['origen_id' => $origen->id, 'modo' => 'conservar'],
        );

        $respuesta->assertRedirect(route('exportaciones.clientes.show', $destino));
        // El existente CONSERVA su precio; el nuevo se copia; el inactivo no viaja.
        $this->assertSame('150.00', $destino->productos()->where('exportacion_producto_id', $comun->id)->firstOrFail()->precio_caja);
        $this->assertSame('200.00', $destino->productos()->where('exportacion_producto_id', $soloOrigen->id)->firstOrFail()->precio_caja);
        $this->assertSame(2, $destino->productos()->count());
    }

    public function test_copiar_precios_sobrescribiendo_existentes(): void
    {
        $comun = $this->producto();
        $origen = $this->cliente('ORIGEN');
        $destino = $this->cliente('DESTINO');
        $origen->productos()->create(['exportacion_producto_id' => $comun->id, 'precio_caja' => 100.00, 'activo' => true]);
        $destino->productos()->create(['exportacion_producto_id' => $comun->id, 'precio_caja' => 150.00, 'activo' => true]);

        $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.copiar', $destino),
            ['origen_id' => $origen->id, 'modo' => 'sobrescribir'],
        );

        $this->assertSame('100.00', $destino->productos()->where('exportacion_producto_id', $comun->id)->firstOrFail()->precio_caja);
        // Sigue habiendo UNA sola asignación (unique cliente+producto respetado).
        $this->assertSame(1, $destino->productos()->count());
    }

    public function test_copiar_no_permite_origen_igual_a_destino(): void
    {
        $cliente = $this->cliente('MISMO');

        $respuesta = $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.copiar', $cliente),
            ['origen_id' => $cliente->id, 'modo' => 'conservar'],
        );

        $respuesta->assertSessionHasErrors('origen_id');
    }

    // ---------- 6) snapshots de exportaciones viejas no cambian ----------

    public function test_copiar_y_sobrescribir_precios_no_toca_snapshots_viejos(): void
    {
        $producto = $this->producto();
        $origen = $this->cliente('ORIGEN');
        $destino = $this->cliente('DESTINO');
        $origen->productos()->create(['exportacion_producto_id' => $producto->id, 'precio_caja' => 100.00, 'activo' => true]);
        $destino->productos()->create(['exportacion_producto_id' => $producto->id, 'precio_caja' => 150.00, 'activo' => true]);

        // Exportación vieja del destino con el precio de aquel momento (150).
        $this->actingAs($this->usuario())->post(route('exportaciones.store'), [
            'exportacion_cliente_id' => $destino->id,
            'cliente_nombre' => $destino->nombre,
            'exportador_nombre' => 'EXPORTADOR',
            'fecha' => '2026-07-08',
            'items' => [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 3]],
        ]);
        $item = ExportacionItem::firstOrFail();
        $this->assertSame('150.00', $item->precio_caja);

        // Sobrescribir la lista de precios del destino desde el origen.
        $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.copiar', $destino),
            ['origen_id' => $origen->id, 'modo' => 'sobrescribir'],
        );

        // La lista cambió (150 → 100) pero el snapshot del item NO.
        $this->assertSame('100.00', $destino->productos()->firstOrFail()->precio_caja);
        $this->assertSame('150.00', $item->refresh()->precio_caja);
        // El precio por unidad del item también sale del snapshot: 150 / 216 = 0.69.
        $this->assertSame(0.69, $item->precioPorUnidad());
    }

    // ---------- 4) asignar catálogo sin ceros silenciosos ----------

    public function test_asignar_catalogo_omite_productos_sin_precio_base_o_en_cero(): void
    {
        $conPrecio = $this->producto(['precio_caja' => 270.00]);
        $sinPrecio = $this->producto(['precio_caja' => null]);
        $enCero = $this->producto(['precio_caja' => 0]);
        $cliente = $this->cliente('CLIENTE');

        $this->actingAs($this->usuario())->post(route('exportaciones.clientes.productos.asignar-catalogo', $cliente));

        // Solo se asignó el que tiene precio base > 0; nada quedó en $0 silenciosamente.
        $this->assertSame(1, $cliente->productos()->count());
        $this->assertSame($conPrecio->id, $cliente->productos()->firstOrFail()->exportacion_producto_id);
    }

    // ---------- 5) precio cero con confirmación ----------

    public function test_precio_cero_requiere_confirmacion_explicita(): void
    {
        $producto = $this->producto();
        $cliente = $this->cliente('CLIENTE');

        // Sin confirmación: bloqueado.
        $respuesta = $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.store', $cliente),
            ['exportacion_producto_id' => $producto->id, 'precio_caja' => 0],
        );
        $respuesta->assertSessionHasErrors('precio_caja');
        $this->assertSame(0, $cliente->productos()->count());

        // Con confirmación: pasa.
        $this->actingAs($this->usuario())->post(
            route('exportaciones.clientes.productos.store', $cliente),
            ['exportacion_producto_id' => $producto->id, 'precio_caja' => 0, 'confirmar_cero' => 1],
        );
        $this->assertSame('0.00', $cliente->productos()->firstOrFail()->precio_caja);

        // Editar a $0 sin confirmar también se bloquea; el precio no cambia... (ya está en 0)
        // Caso real: asignación con precio > 0 que se intenta dejar en 0 sin confirmar.
        $otro = $this->producto();
        $asignacion = $cliente->productos()->create(['exportacion_producto_id' => $otro->id, 'precio_caja' => 50.00, 'activo' => true]);
        $respuesta = $this->actingAs($this->usuario())->patch(
            route('exportaciones.clientes.productos.update', [$cliente, $asignacion]),
            ['precio_caja' => 0],
        );
        $respuesta->assertSessionHasErrors('precio_caja');
        $this->assertSame('50.00', $asignacion->refresh()->precio_caja);

        // Negativo: bloqueado por min:0.
        $respuesta = $this->actingAs($this->usuario())->patch(
            route('exportaciones.clientes.productos.update', [$cliente, $asignacion]),
            ['precio_caja' => -5],
        );
        $respuesta->assertSessionHasErrors('precio_caja');
        $this->assertSame('50.00', $asignacion->refresh()->precio_caja);
    }
}
