<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Auto-agregar productos al borrador escribiendo la cantidad (idempotente por producto)
 * y orden fijo de la orden de compra en los productos agregados.
 */
class DteAutoAgregarProductoTest extends TestCase
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
        $this->seed(CatalogosMhSeeder::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function borrador(Cliente $cliente): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
    }

    private function producto(string $nombre, ?string $barra = null, float $precio = 10): Producto
    {
        return Producto::factory()->create([
            'nombre' => $nombre, 'codigo_barra' => $barra,
            'precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value, 'activo' => true,
        ]);
    }

    private function setCantidad(User $u, Dte $dte, Producto $p, $cantidad)
    {
        return $this->actingAs($u)->post(route('facturacion.productos.cantidad', [$dte, $p]), ['cantidad' => $cantidad]);
    }

    // --- Auto-agregar / actualizar / quitar ---

    public function test_escribir_cantidad_agrega_el_producto(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($u, $dte, $p, 3)->assertRedirect();

        $dte->refresh();
        $this->assertCount(1, $dte->lineas);
        $this->assertSame(3, (int) $dte->lineas->first()->cantidad);
        $this->assertSame('30.00', $dte->total_gravado); // 3 × 10
        $this->assertSame('33.90', $dte->total_pagar);    // + IVA 13%
    }

    public function test_cambiar_cantidad_actualiza_la_linea_sin_duplicar(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($u, $dte, $p, 3)->assertRedirect();
        $this->setCantidad($u, $dte, $p, 5)->assertRedirect();

        $dte->refresh();
        $this->assertCount(1, $dte->lineas); // NO duplica
        $this->assertSame(5, (int) $dte->lineas->first()->cantidad);
        $this->assertDatabaseCount('dte_lineas', 1);
        $this->assertSame('50.00', $dte->total_gravado);
    }

    public function test_cantidad_cero_elimina_la_linea_existente(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($u, $dte, $p, 4)->assertRedirect();
        $this->assertDatabaseCount('dte_lineas', 1);

        $this->setCantidad($u, $dte, $p, 0)->assertRedirect();

        $dte->refresh();
        $this->assertCount(0, $dte->lineas);
        $this->assertSame('0.00', $dte->total_gravado);
    }

    public function test_cantidad_vacia_quita_la_linea(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($u, $dte, $p, 2)->assertRedirect();
        $this->setCantidad($u, $dte, $p, '')->assertRedirect();

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_cantidad_cero_sin_linea_no_hace_nada_ni_falla(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($u, $dte, $p, 0)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_producto_sin_precio_aplicable_no_se_agrega(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('SIN PRECIO', '7412201700031', precio: 0);

        $this->setCantidad($u, $dte, $p, 3)->assertSessionHasErrors('cantidad');

        $this->assertDatabaseCount('dte_lineas', 0);
    }

    public function test_solo_gestores_pueden_fijar_cantidad(): void
    {
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());
        $p = $this->producto('CANILLITAS', '7412201700031');

        $this->setCantidad($this->usuario('consulta'), $dte, $p, 2)->assertForbidden();
        $this->assertDatabaseCount('dte_lineas', 0);
    }

    // --- Orden de la orden de compra ---

    public function test_productos_agregados_salen_en_el_orden_de_la_orden_de_compra(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());

        $semilla = $this->producto('SEMILLA DE MARAÑON', '7412201700178'); // rank 0
        $canillitas = $this->producto('CANILLITAS', '7412201700031');       // rank 8
        $mazapan = $this->producto('MAZAPAN', '7412201700115');             // rank 17
        $fuera = $this->producto('PRODUCTO Z', '9999999999999');            // fuera de la lista

        // Se agregan en orden REVUELTO.
        $this->setCantidad($u, $dte, $mazapan, 1)->assertRedirect();
        $this->setCantidad($u, $dte, $fuera, 1)->assertRedirect();
        $this->setCantidad($u, $dte, $canillitas, 1)->assertRedirect();
        $this->setCantidad($u, $dte, $semilla, 1)->assertRedirect();

        // El panel de agregados usa title="{descripcion}"; verifica el orden fijo (fuera al final).
        $this->actingAs($u)->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSeeInOrder([
                'title="SEMILLA DE MARAÑON"',
                'title="CANILLITAS"',
                'title="MAZAPAN"',
                'title="PRODUCTO Z"',
            ], false);
    }

    public function test_catalogo_disponible_tambien_respeta_el_orden_de_la_oc(): void
    {
        $u = $this->usuario('facturacion');
        $dte = $this->borrador(Cliente::factory()->contribuyente()->create());

        $this->producto('MAZAPAN', '7412201700115');           // rank 17
        $this->producto('SEMILLA DE MARAÑON', '7412201700178'); // rank 0
        $this->producto('CANILLITAS', '7412201700031');         // rank 8

        $this->actingAs($u)->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSeeInOrder(['SEMILLA DE MARAÑON', 'CANILLITAS', 'MAZAPAN']);
    }
}
