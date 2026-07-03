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
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Catálogo de "Productos disponibles" en la edición del borrador: listado ya
 * visible con precio resuelto (sala → cliente → general), filtro en vivo,
 * cantidad entera, sin precio/descuento manual; NC sin catálogo de productos.
 */
class DteCatalogoBorradorTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    /** @param array<string, mixed> $attrs */
    private function producto(array $attrs = []): Producto
    {
        return Producto::factory()->create(array_merge([
            'precio_unitario' => 0.50,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
            'activo' => true,
        ], $attrs));
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

    private function ccfGenerado(array $emisor, Producto $producto): Dte
    {
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 5);
        app(DteGeneracionService::class)->generar($dte);

        // La NC exige un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    // --- A/B: catálogo visible ---

    public function test_edicion_ccf_muestra_catalogo_de_productos_disponibles(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $this->producto(['codigo' => 'DUL-001', 'codigo_barra' => '7412201700031', 'nombre' => 'CANILLITAS']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('Filtrar por nombre, código interno o código de barra')
            ->assertSee('DUL-001')
            ->assertSee('7412201700031')
            ->assertSee('CANILLITAS')
            ->assertSee('precio general');
    }

    public function test_edicion_ccf_calleja_muestra_precio_especial(): void
    {
        $emisor = $this->emisor();
        $calleja = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.', 'nombre_comercial' => null]);
        $producto = $this->producto(['codigo' => 'DUL-001', 'nombre' => 'CANILLITAS', 'precio_unitario' => 0.50]);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $calleja->id, 'precio' => 1.05, 'activo' => true]);

        $dte = $this->ccf($calleja, $emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('CANILLITAS')
            ->assertSee('1.0500')          // precio especial, 4 decimales
            ->assertSee('especial')
            ->assertSee('Calleja');        // etiqueta del cliente en el origen
    }

    // --- C/D: productos sin precio no se agregan ---

    public function test_producto_sin_precio_no_se_puede_agregar(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $sinPrecio = $this->producto(['codigo' => 'DUL-009', 'nombre' => 'SIN PRECIO', 'precio_unitario' => 0]);

        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('SIN PRECIO')
            ->assertSee('Sin precio');

        // Aunque se fuerce el POST, no se agrega.
        $this->actingAs($facturacion)
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $sinPrecio->id, 'cantidad' => 1])
            ->assertSessionHasErrors('producto_id');

        $this->assertCount(0, $dte->refresh()->lineas);
    }

    // --- E: agregar desde el catálogo congela cantidad entera y precio aplicado ---

    public function test_agregar_desde_catalogo_guarda_cantidad_entera_y_precio_aplicado(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = $this->producto(['precio_unitario' => 0.50]);
        ProductoPrecioCliente::create(['producto_id' => $producto->id, 'cliente_id' => $cliente->id, 'precio' => 0.45, 'activo' => true]);

        $dte = $this->ccf($cliente, $emisor);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 3])
            ->assertRedirect();

        $linea = $dte->refresh()->lineas->first();
        $this->assertNotNull($linea);
        $this->assertSame('0.450000', $linea->precio_unitario); // precio especial congelado
        $this->assertSame(3, (int) $linea->cantidad);            // entero
    }

    public function test_cantidad_decimal_desde_catalogo_falla(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->producto();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1.0003])
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $dte->refresh()->lineas);
    }

    public function test_cantidad_cero_desde_catalogo_falla(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $producto = $this->producto();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 0])
            ->assertSessionHasErrors('cantidad');

        $this->assertCount(0, $dte->refresh()->lineas);
    }

    // --- E: sin precio ni descuento manual ---

    public function test_catalogo_no_muestra_input_de_precio_ni_descuento_manual(): void
    {
        $emisor = $this->emisor();
        $dte = $this->ccf(Cliente::factory()->contribuyente()->create(), $emisor);
        $this->producto(['nombre' => 'CANILLITAS']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertDontSee('name="precio_unitario"', false)
            ->assertDontSee('name="descuento_monto"', false);
    }

    // --- G: aplica a Factura 01 y Exportación ---

    public function test_factura_01_muestra_catalogo_disponible(): void
    {
        $emisor = $this->emisor();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->producto(['nombre' => 'CANILLITAS']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('CANILLITAS');
    }

    public function test_exportacion_muestra_catalogo_disponible(): void
    {
        $emisor = $this->emisor();
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => Cliente::factory()->exportacion()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->producto(['nombre' => 'CANILLITAS']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('Productos disponibles')
            ->assertSee('CANILLITAS');
    }

    // --- G: Nota de crédito NO muestra catálogo de productos ---

    public function test_nc_pronto_pago_no_muestra_catalogo_sino_conceptos(): void
    {
        $emisor = $this->emisor();
        // Toda NC (incluida pronto pago) requiere un CCF aceptado relacionado.
        $ccf = $this->ccfGenerado($emisor, Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]));

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Pronto pago',
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertDontSee('Productos disponibles')
            ->assertSee('Agregar concepto de ajuste');
    }

    public function test_nc_devolucion_mantiene_lineas_del_ccf_original(): void
    {
        $emisor = $this->emisor();
        $producto = $this->producto(['nombre' => 'CANILLITAS', 'precio_unitario' => 1.05]);
        $ccf = $this->ccfGenerado($emisor, $producto);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.nota-credito.store', $ccf), [
                'tipo' => 'devolucion_producto',
                'motivo' => 'Devolución parcial',
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->firstOrFail();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->assertDontSee('Productos disponibles')
            ->assertSee('Líneas del documento original')
            ->assertSee('CANILLITAS');
    }
}
