<?php

namespace Tests\Feature\Importacion;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importaciones › Precios sobre un producto que ya existe.
 *
 * El archivo es de PRECIOS del cliente: no trae si el producto lleva inventario, si está
 * activo, ni su tipo o impuesto. Al reimportar se le ponían siempre los valores de un
 * producto nuevo (sin inventario, activo, bien, gravado): un exento pasaba a gravado y un
 * inactivo volvía al catálogo sin que nadie lo notara. Al crear uno nuevo, esos valores
 * siguen siendo los de siempre.
 */
class ImportacionPreciosProductoExistenteTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function importar(string $precio = '1.0500')
    {
        $archivo = UploadedFile::fake()->createWithContent('precios.csv', implode("\n", [
            'Código interno,Código de barra,Descripción de producto,Factor de empaque,Fecha de inicio,Costo nuevo / unidad libra / precio',
            "PRB-1,7400000000017,PRODUCTO DE PRUEBA,,,{$precio}",
        ]));

        return $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->from(route('importaciones.index'))
            ->post(route('importaciones.precios.importar'), ['cliente_id' => $this->cliente->id, 'archivo' => $archivo]);
    }

    public function test_reimportar_no_cambia_inventario_estado_tipo_ni_impuesto_de_un_producto_existente(): void
    {
        $producto = Producto::factory()->create([
            'codigo' => 'PRB-1', 'nombre' => 'PRODUCTO DE PRUEBA', 'precio_unitario' => '2.00',
            'maneja_inventario' => true, 'activo' => false,
            'tipo_producto' => TipoProducto::Servicio->value, 'tipo_impuesto' => TipoImpuesto::Exento->value,
        ]);

        // El resumen avisa que sigue inactivo: si no, la oficina cree que ya lo puede facturar.
        $this->importar('1.2000')->assertSessionHas('resumen', fn (array $r) => $r['actualizadas'] === 1
            && str_contains($r['detalles'][0]['detalle'], '$1.2000 (el producto está inactivo: activalo en Productos si se vuelve a vender)'));

        $producto->refresh();
        $this->assertTrue($producto->maneja_inventario, 'Seguía llevando inventario.');
        $this->assertFalse($producto->activo, 'Un producto inactivo no vuelve solo al catálogo.');
        $this->assertSame(TipoProducto::Servicio, $producto->tipo_producto);
        $this->assertSame(TipoImpuesto::Exento, $producto->tipo_impuesto, 'Un exento no pasa a gravado.');
        $this->assertSame(2.0, (float) $producto->precio_unitario, 'El precio general no se pisa.');
        $this->assertSame('1.2000', ProductoPrecioCliente::where('producto_id', $producto->id)->sole()->precio,
            'El precio del cliente sí se actualiza.');
    }

    public function test_un_producto_nuevo_nace_con_los_valores_de_siempre(): void
    {
        $this->importar('1.0500')->assertSessionHas('resumen', fn (array $r) => $r['creadas'] === 1
            && ! str_contains($r['detalles'][0]['detalle'], 'inactivo'));

        $producto = Producto::where('codigo', 'PRB-1')->sole();
        $this->assertFalse($producto->maneja_inventario);
        $this->assertTrue($producto->activo);
        $this->assertSame(TipoProducto::Bien, $producto->tipo_producto);
        $this->assertSame(TipoImpuesto::Gravado, $producto->tipo_impuesto);
        $this->assertSame('1.0500', ProductoPrecioCliente::where('producto_id', $producto->id)->sole()->precio);
    }
}
