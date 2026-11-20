<?php

namespace Tests\Feature\Productos;

use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use App\Services\Dte\PrecioProductoResolver;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Recorrido de los precios especiales de un cliente, como lo hace la oficina: agregar uno en
 * la ficha del producto, cambiarlo por otro, intentar reactivar el viejo, exportar el
 * archivo del cliente, volver a subirlo sin tocarlo y subirlo con un cambio real.
 *
 * Lo que cuenta es el precio con que sale el próximo CCF ({@see PrecioProductoResolver}).
 * Datos inventados.
 */
class PrecioEspecialRecorridoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function agregar(User $usuario, Producto $producto, string $precio, bool $activo = true)
    {
        return $this->actingAs($usuario)->from(route('productos.show', $producto))->post(route('productos.precios.store', $producto), [
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => '', 'precio' => $precio, 'activo' => $activo ? '1' : '0',
        ]);
    }

    private function alternar(User $usuario, Producto $producto, ProductoPrecioCliente $precio)
    {
        return $this->actingAs($usuario)->from(route('productos.show', $producto))
            ->patch(route('productos.precios.toggle-activo', [$producto, $precio]));
    }

    private function precioDelCcf(Producto $producto): string
    {
        return app(PrecioProductoResolver::class)->resolver($producto, $this->cliente->id, null);
    }

    private function activos(Producto $producto)
    {
        return ProductoPrecioCliente::where('producto_id', $producto->id)->where('cliente_id', $this->cliente->id)
            ->whereNull('cliente_sucursal_id')->where('activo', true)->get();
    }

    /** Lo que importa de cada precio y su producto, para comparar antes y después. */
    private function foto(): array
    {
        return ProductoPrecioCliente::with('producto')->orderBy('id')->get()->map(fn ($p) => [
            $p->id, $p->producto_id, $p->precio, $p->activo, $p->fecha_inicio?->toDateString(),
            $p->producto->nombre, $p->producto->activo, $p->producto->maneja_inventario,
            $p->producto->tipo_impuesto->value, (string) $p->producto->precio_unitario,
        ])->all();
    }

    private function importar(User $usuario, string $csv)
    {
        return $this->actingAs($usuario)->from(route('importaciones.index'))->post(route('importaciones.precios.importar'), [
            'cliente_id' => $this->cliente->id, 'archivo' => UploadedFile::fake()->createWithContent('precios.csv', $csv),
        ]);
    }

    public function test_agregar_cambiar_reactivar_exportar_y_reimportar_nunca_deja_dos_activos(): void
    {
        $admin = $this->admin();
        $galleta = Producto::factory()->create(['codigo' => 'PRB-10', 'nombre' => 'GALLETA DE PRUEBA', 'precio_unitario' => '2.00',
            'tipo_impuesto' => TipoImpuesto::Exento->value, 'maneja_inventario' => true]);
        $dulce = Producto::factory()->create(['codigo' => 'PRB-20', 'nombre' => 'DULCE DE PRUEBA', 'precio_unitario' => '3.00']);

        // 1. Precio especial en la ficha. El doble clic no deja un segundo activo.
        $this->agregar($admin, $galleta, '1.50')->assertSessionHas('status', 'Precio por cliente agregado.');
        $this->agregar($admin, $galleta, '1.50')->assertSessionHasErrors('precio');
        $viejo = $this->activos($galleta)->sole();
        $this->assertSame('1.5000', $this->precioDelCcf($galleta));

        // 2. Otro precio: el primero se inactiva y el nuevo se agrega activo.
        $this->alternar($admin, $galleta, $viejo)->assertSessionHas('status', 'Precio inactivado.');
        $this->agregar($admin, $galleta, '1.75')->assertSessionHas('status');
        $nuevo = $this->activos($galleta)->sole();
        $this->assertNotSame($viejo->id, $nuevo->id);
        $this->assertSame('1.7500', $this->precioDelCcf($galleta));

        // 3. Reactivar el viejo con el nuevo activo: se niega, la ficha lo dice y nada cambia.
        $this->alternar($admin, $galleta, $viejo)->assertSessionHasErrors('precio');
        $this->assertFalse($viejo->refresh()->activo);
        $this->assertCount(1, $this->activos($galleta));
        $this->actingAs($admin)->get(route('productos.show', $galleta))->assertOk()
            ->assertSee('No se activó: ya hay otro precio activo para ese cliente y sala.');
        $this->assertSame('1.7500', $this->precioDelCcf($galleta));

        $this->agregar($admin, $dulce, '2.40')->assertSessionHas('status');

        // 4. Exportar el archivo del cliente y volver a subirlo sin tocarlo: nada cambia.
        $antes = $this->foto();
        $csv = $this->actingAs($admin)->get(route('importaciones.precios.exportar', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->streamedContent();
        $this->importar($admin, $csv)->assertSessionHas('resumen', fn (array $r) => $r['creadas'] === 0 && $r['ignoradas'] === 1);
        $this->assertSame($antes, $this->foto(), 'Reimportar el archivo exportado no cambia ningún precio ni producto.');
        // Doble envío del mismo archivo: tampoco.
        $this->importar($admin, $csv)->assertSessionHas('resumen');
        $this->assertSame($antes, $this->foto());

        // 5. El mismo archivo con un cambio real (la galleta sube): cambia solo ese.
        $cambiado = str_replace('1.7500', '1.9000', $csv);
        $this->assertNotSame($csv, $cambiado);
        $this->importar($admin, $cambiado)->assertSessionHas('resumen', fn (array $r) => collect($r['detalles'])
            ->contains(fn (array $d) => str_contains($d['detalle'], '$1.7500 → $1.9000')));
        $this->assertSame('1.9000', $this->precioDelCcf($galleta));
        $this->assertSame('1.9000', $nuevo->refresh()->precio, 'Cambia el que estaba vigente, no se crea otro.');
        $this->assertCount(1, $this->activos($galleta));
        $this->assertFalse($viejo->refresh()->activo);
        $this->assertSame('1.5000', $viejo->precio);
        $this->assertSame('2.4000', $this->precioDelCcf($dulce), 'El otro producto no cambia.');
        $galleta->refresh();
        $this->assertSame(TipoImpuesto::Exento, $galleta->tipo_impuesto, 'El producto conserva su impuesto.');
        $this->assertTrue($galleta->maneja_inventario);
        $this->assertSame(3, ProductoPrecioCliente::count());
    }

    public function test_sin_permiso_de_productos_no_agrega_ni_reactiva_precios_y_nada_cambia(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'PRB-10']);
        $precio = $producto->preciosCliente()->create(['cliente_id' => $this->cliente->id, 'precio' => '1.50', 'activo' => false]);

        foreach (['jefatura', 'contabilidad', 'vendedor'] as $rol) {
            $quien = User::factory()->create()->assignRole($rol);
            $this->agregar($quien, $producto, '9.99')->assertForbidden();
            $this->alternar($quien, $producto, $precio)->assertForbidden();
        }

        $this->assertSame(1, ProductoPrecioCliente::count());
        $this->assertFalse($precio->refresh()->activo);
    }
}
