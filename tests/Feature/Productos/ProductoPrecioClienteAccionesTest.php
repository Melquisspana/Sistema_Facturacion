<?php

namespace Tests\Feature\Productos;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Inactivar / Activar» y «Eliminar» de un precio especial en la ficha del producto.
 *
 * Lo que importa es el precio con que sale el próximo CCF: con dos precios activos para el
 * mismo cliente y sala, el CCF toma el de id más alto sin que nadie lo note. Por eso
 * reactivar uno viejo cuando ya hay otro activo se impide, igual que al agregarlo.
 */
class ProductoPrecioClienteAccionesTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        $this->producto = Producto::factory()->create(['precio_unitario' => '2.00']);
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function precio(array $datos = []): ProductoPrecioCliente
    {
        return $this->producto->preciosCliente()->create($datos + [
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => null, 'precio' => '1.50', 'activo' => true,
        ]);
    }

    /** Precio unitario de una línea nueva en un CCF de este cliente, como lo arma Facturación. */
    private function precioDelProximoCcf(?ClienteSucursal $sala = null): string
    {
        $empresa = Empresa::create(['razon_social' => 'Emisor de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->cliente,
            'cliente_sucursal_id' => $sala?->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);

        return $borradores->agregarLineaDesdeProducto($dte, $this->producto, cantidad: 1)->precio_unitario;
    }

    private function alternar(User $usuario, ProductoPrecioCliente $precio, ?Producto $producto = null)
    {
        return $this->actingAs($usuario)->from(route('productos.show', $this->producto))
            ->patch(route('productos.precios.toggle-activo', [$producto ?? $this->producto, $precio]));
    }

    // ------------------------------------------------------------------ inactivar / activar

    public function test_inactivar_y_volver_a_activar_el_precio_cambia_el_precio_del_proximo_ccf(): void
    {
        $admin = $this->usuario();
        $precio = $this->precio();

        $this->alternar($admin, $precio)->assertRedirect(route('productos.show', $this->producto))
            ->assertSessionHas('status', 'Precio inactivado.');
        $this->assertFalse($precio->refresh()->activo);
        $this->assertSame('2.000000', $this->precioDelProximoCcf(), 'Inactivo, el CCF usa el precio general.');

        $this->alternar($admin, $precio)->assertSessionHas('status', 'Precio activado.');
        $this->assertTrue($precio->refresh()->activo);
        $this->assertSame(2, Activity::where('log_name', 'precio_producto')->where('subject_id', $precio->id)
            ->where('description', 'actualizó un precio especial')->where('causer_id', $admin->id)->count());
    }

    public function test_no_se_reactiva_un_precio_viejo_si_ya_hay_otro_activo_para_el_mismo_cliente_y_sala(): void
    {
        $viejo = $this->precio(['precio' => '1.40', 'activo' => false]);
        $vigente = $this->precio(['precio' => '1.50']);

        $admin = $this->usuario();
        $this->alternar($admin, $viejo)->assertRedirect(route('productos.show', $this->producto))
            ->assertSessionHasErrors('precio');
        // La ficha dice por qué, en vez de dejar el botón sin efecto.
        $this->actingAs($admin)->from(route('productos.show', $this->producto))->followingRedirects()
            ->patch(route('productos.precios.toggle-activo', [$this->producto, $viejo]))
            ->assertOk()->assertSee('ya hay otro precio activo para ese cliente y sala');

        $this->assertFalse($viejo->refresh()->activo);
        $this->assertTrue($vigente->refresh()->activo);
        $this->assertSame(1, ProductoPrecioCliente::where('producto_id', $this->producto->id)->where('activo', true)->count());
        $this->assertSame('1.500000', $this->precioDelProximoCcf());
    }

    public function test_el_precio_de_una_sala_se_reactiva_aunque_el_cliente_tenga_su_precio_general_activo(): void
    {
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id]);
        $this->precio(['precio' => '1.50']);
        $deLaSala = $this->precio(['cliente_sucursal_id' => $sala->id, 'precio' => '1.30', 'activo' => false]);

        $this->alternar($this->usuario(), $deLaSala)->assertSessionHas('status', 'Precio activado.');

        $this->assertTrue($deLaSala->refresh()->activo);
        $this->assertSame('1.300000', $this->precioDelProximoCcf($sala));
    }

    public function test_un_precio_de_otro_producto_da_404_y_no_cambia(): void
    {
        $otro = Producto::factory()->create();
        $precio = $this->precio();

        $this->alternar($this->usuario(), $precio, $otro)->assertNotFound();

        $this->assertTrue($precio->refresh()->activo);
    }

    public function test_un_rol_sin_permiso_sobre_productos_no_cambia_el_precio(): void
    {
        $precio = $this->precio();

        foreach (['facturacion', 'jefatura', 'contabilidad'] as $rol) {
            $this->alternar($this->usuario($rol), $precio)->assertForbidden();
        }

        $this->assertTrue($precio->refresh()->activo);
    }

    // ------------------------------------------------------------------ eliminar

    public function test_eliminar_el_precio_lo_saca_de_la_ficha_queda_en_la_bitacora_y_el_ccf_usa_el_general(): void
    {
        $admin = $this->usuario();
        $precio = $this->precio(['precio' => '1.25']);
        $this->assertSame('1.250000', $this->precioDelProximoCcf());

        $this->actingAs($admin)->delete(route('productos.precios.destroy', [$this->producto, $precio]))
            ->assertRedirect(route('productos.show', $this->producto))
            ->assertSessionHas('status', 'Precio eliminado.');

        $this->assertDatabaseMissing('producto_precios_cliente', ['id' => $precio->id]);
        $this->assertSame(1, Activity::where('log_name', 'precio_producto')->where('subject_id', $precio->id)
            ->where('description', 'eliminó un precio especial')->where('causer_id', $admin->id)->count());
        $this->actingAs($admin)->get(route('productos.show', $this->producto))->assertOk()
            ->assertSee('Sin precios especiales. Se usa el precio general.');
        $this->assertSame('2.000000', $this->precioDelProximoCcf());
    }

    public function test_eliminar_un_precio_de_otro_producto_da_404_y_no_borra(): void
    {
        $otro = Producto::factory()->create();
        $precio = $this->precio();

        $this->actingAs($this->usuario())->delete(route('productos.precios.destroy', [$otro, $precio]))->assertNotFound();

        $this->assertDatabaseHas('producto_precios_cliente', ['id' => $precio->id]);
    }

    public function test_un_rol_sin_permiso_sobre_productos_no_elimina_precios(): void
    {
        $precio = $this->precio();

        foreach (['facturacion', 'jefatura', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->delete(route('productos.precios.destroy', [$this->producto, $precio]))->assertForbidden();
        }

        $this->assertDatabaseHas('producto_precios_cliente', ['id' => $precio->id]);
    }
}
