<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clientes › ficha › Exportación › quitar un producto de la lista de precios, por su ruta
 * (clientes.exportacion.productos.destroy).
 *
 * Hoy ningún botón la usa: los precios se cambian desde Productos › «De exportación».
 * Retirarla o dejarla es una decisión de Melqui; acá se prueba tal como está. Quitar
 * borra solo el precio de ESE cliente y ese producto: ni el de otro cliente, ni el
 * catálogo, ni las listas de empaque ya hechas, que guardan su propia copia. Datos ficticios.
 */
class ClienteExportacionQuitarPrecioRutasTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** @return array{cliente: Cliente, perfil: ExportacionCliente} */
    private function clienteHabilitado(string $nombre = 'Cliente de prueba'): array
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $nombre, 'activo' => true]);

        return ['cliente' => $cliente, 'perfil' => $perfil];
    }

    private function producto(string $nombre = 'Producto de prueba'): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => $nombre, 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40, 'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    private function precio(ExportacionCliente $perfil, ExportacionProducto $producto, float $precio): ExportacionClienteProducto
    {
        return ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id,
            'precio_caja' => $precio, 'activo' => true,
        ]);
    }

    public function test_quitar_borra_solo_ese_precio_y_no_toca_otros_clientes_el_catalogo_ni_las_listas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        ['perfil' => $otroPerfil] = $this->clienteHabilitado('Otro cliente de prueba');
        $productoA = $this->producto();
        $productoB = $this->producto('Otro producto de prueba');
        $quitar = $this->precio($perfil, $productoA, 150.00);
        $queda = $this->precio($perfil, $productoB, 99.50);
        $delOtro = $this->precio($otroPerfil, $productoA, 160.00);

        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $linea = $lista->items()->create([
            'exportacion_producto_id' => $productoA->id, 'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 150.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);
        $lineaAntes = $linea->fresh()->getAttributes();

        $this->actingAs($this->usuario())
            ->delete(route('clientes.exportacion.productos.destroy', [$cliente, $quitar]))
            ->assertRedirect(route('clientes.show', $cliente))
            ->assertSessionHas('status', 'Producto quitado de la lista de precios del cliente.');

        $this->assertModelMissing($quitar);
        $this->assertModelExists($queda);
        $this->assertModelExists($delOtro);
        $this->assertSame('160.00', (string) $delOtro->fresh()->precio_caja);
        $this->assertModelExists($productoA);
        $this->assertEquals($lineaAntes, $linea->fresh()->getAttributes());
    }

    public function test_el_precio_de_otro_cliente_da_404_y_no_se_borra(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        ['perfil' => $otroPerfil] = $this->clienteHabilitado('Otro cliente de prueba');
        $producto = $this->producto();
        $this->precio($perfil, $producto, 150.00);
        $ajeno = $this->precio($otroPerfil, $producto, 160.00);

        $this->actingAs($this->usuario())
            ->delete(route('clientes.exportacion.productos.destroy', [$cliente, $ajeno]))
            ->assertNotFound();

        $this->assertSame(2, ExportacionClienteProducto::count());
    }

    public function test_un_cliente_sin_perfil_de_exportacion_da_404(): void
    {
        $sinPerfil = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente sin perfil de prueba']);
        ['perfil' => $perfil] = $this->clienteHabilitado();
        $precio = $this->precio($perfil, $this->producto(), 150.00);

        $this->actingAs($this->usuario())
            ->delete(route('clientes.exportacion.productos.destroy', [$sinPerfil, $precio]))
            ->assertNotFound();

        $this->assertModelExists($precio);
    }

    public function test_el_doble_clic_no_borra_nada_mas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $quitar = $this->precio($perfil, $this->producto(), 150.00);
        $queda = $this->precio($perfil, $this->producto('Otro producto de prueba'), 99.50);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->delete(route('clientes.exportacion.productos.destroy', [$cliente, $quitar]))->assertRedirect();
        $this->actingAs($usuario)->delete(route('clientes.exportacion.productos.destroy', [$cliente, $quitar->id]))->assertNotFound();

        $this->assertModelExists($queda);
        $this->assertSame(1, ExportacionClienteProducto::count());
    }

    public function test_sin_permiso_de_gestionar_exportaciones_da_403_y_no_borra(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $precio = $this->precio($perfil, $this->producto(), 150.00);

        foreach (['jefatura', 'contabilidad', 'produccion'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->delete(route('clientes.exportacion.productos.destroy', [$cliente, $precio]))
                ->assertForbidden();
        }

        $this->assertModelExists($precio);
        $this->assertSame(0, ExportacionItem::count());
    }
}
