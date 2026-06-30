<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrecioYCantidadTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    private function productoGravado(float $precio = 0.50): Producto
    {
        return Producto::factory()->create(['precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function ccf(Cliente $cliente, array $emisor, ?ClienteSucursal $sucursal = null): Dte
    {
        return $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente,
            'cliente_sucursal_id' => $sucursal?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
    }

    // --- Cantidad entera ---

    public function test_cantidad_decimal_falla(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->productoGravado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1.5])
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $dte->refresh()->lineas);
    }

    public function test_cantidad_cero_falla(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->productoGravado();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 0])
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $dte->refresh()->lineas);
    }

    public function test_cantidad_entera_funciona(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->productoGravado(10);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 3])
            ->assertRedirect();

        $this->assertSame('30.00', $dte->refresh()->total_gravado);
    }

    public function test_no_usa_descuento_manual_por_linea(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->productoGravado(10);

        // Aunque se intente mandar descuento_monto, se ignora.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1, 'descuento_monto' => 50])
            ->assertRedirect();

        $this->assertSame('0.00', $dte->refresh()->lineas->first()->descuento_monto);
    }

    // --- Precio por cliente/sucursal ---

    public function test_usa_precio_general_si_no_hay_precio_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $dte = $this->ccf($cliente, $emisor);
        $producto = $this->productoGravado(0.50);

        $linea = $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->assertSame('0.500000', $linea->precio_unitario);
    }

    public function test_usa_precio_por_cliente_si_existe(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->productoGravado(0.50);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => true]);

        $dte = $this->ccf($cliente, $emisor);
        $linea = $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->assertSame('0.450000', $linea->precio_unitario);
    }

    public function test_usa_precio_por_sucursal_con_prioridad(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
        $producto = $this->productoGravado(0.50);

        // Cliente 0.45 y sucursal 0.40 → debe ganar la sucursal.
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => true]);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sucursal->id, 'precio' => 0.40, 'activo' => true]);

        $dte = $this->ccf($cliente, $emisor, $sucursal);
        $linea = $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        $this->assertSame('0.400000', $linea->precio_unitario);
    }

    public function test_linea_conserva_precio_snapshot_si_cambia_despues(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->productoGravado(0.50);
        $precio = ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => true]);

        $dte = $this->ccf($cliente, $emisor);
        $linea = $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        // El precio especial cambia DESPUÉS.
        $precio->update(['precio' => 0.99]);

        $this->assertSame('0.450000', $linea->refresh()->precio_unitario); // snapshot intacto
    }

    // --- UI de precios por producto ---

    public function test_admin_agrega_precio_por_cliente(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->productoGravado(0.50);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.precios.store', $producto), ['cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => '1'])
            ->assertRedirect(route('productos.show', $producto));

        $this->assertDatabaseHas('producto_precios_cliente', ['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.4500]);
    }

    public function test_no_duplica_precio_activo(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->productoGravado(0.50);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => true]);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('productos.precios.store', $producto), ['cliente_id' => $cliente->id, 'precio' => 0.40, 'activo' => '1'])
            ->assertSessionHasErrors('precio');

        $this->assertSame(1, ProductoPrecioCliente::where('producto_id', $producto->id)->count());
    }

    public function test_consulta_no_puede_agregar_precio(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->productoGravado(0.50);

        $this->actingAs($this->usuario('consulta'))
            ->post(route('productos.precios.store', $producto), ['cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => '1'])
            ->assertForbidden();
    }
}
