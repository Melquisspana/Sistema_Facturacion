<?php

namespace Tests\Feature\Importacion;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use App\Services\Dte\PrecioProductoResolver;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Importaciones › Precios por la pantalla: subir el CSV de un cliente y mirar el precio que
 * quedó en producto_precios_cliente —el que usa el próximo CCF—, no solo la bitácora.
 */
class ImportacionPreciosRutaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function archivo(string $precio = '1.0500', string $fecha = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('precios.csv', implode("\n", [
            'Código interno,Código de barra,Descripción de producto,Factor de empaque,Fecha de inicio,Costo nuevo / unidad libra / precio',
            "PRB-1,7400000000017,PRODUCTO DE PRUEBA,BOLSA,{$fecha},{$precio}",
        ]));
    }

    private function importar(User $usuario, UploadedFile $archivo, ?Cliente $cliente = null)
    {
        return $this->actingAs($usuario)->from(route('importaciones.index'))
            ->post(route('importaciones.precios.importar'), ['cliente_id' => ($cliente ?? $this->cliente)->id, 'archivo' => $archivo]);
    }

    private function precioDelCcf(Producto $producto, ?int $salaId = null): string
    {
        return app(PrecioProductoResolver::class)->resolver($producto, $this->cliente->id, $salaId);
    }

    public function test_el_administrador_importa_y_el_precio_queda_guardado_para_el_cliente(): void
    {
        $admin = $this->usuario();

        $this->importar($admin, $this->archivo('1.0500', '2026-01-01'))
            ->assertRedirect(route('importaciones.index'))->assertSessionHas('resumen');

        $producto = Producto::where('codigo', 'PRB-1')->sole();
        $precio = ProductoPrecioCliente::where('producto_id', $producto->id)->sole();
        $this->assertSame($this->cliente->id, $precio->cliente_id);
        $this->assertNull($precio->cliente_sucursal_id);
        $this->assertSame('1.0500', $precio->precio);
        $this->assertTrue($precio->activo);
        $this->assertSame('2026-01-01', $precio->fecha_inicio->toDateString());
        $this->assertSame('1.0500', $this->precioDelCcf($producto));
        $this->assertSame(1, Activity::where('log_name', 'importacion')->where('causer_id', $admin->id)
            ->where('subject_id', $this->cliente->id)->count());
    }

    public function test_reimportar_con_otro_precio_actualiza_el_mismo_registro(): void
    {
        $admin = $this->usuario();
        $this->importar($admin, $this->archivo('1.0500'));
        $this->importar($admin, $this->archivo('1.2000'));

        $producto = Producto::where('codigo', 'PRB-1')->sole();
        $this->assertSame(1, ProductoPrecioCliente::where('producto_id', $producto->id)->count());
        $this->assertSame('1.2000', $this->precioDelCcf($producto));
    }

    /**
     * Con un precio viejo inactivo y otro activo, la importación reactivaba el viejo y dejaba
     * dos activos: el CCF seguía saliendo con el de id más alto, no con el del archivo.
     */
    public function test_con_un_precio_viejo_inactivo_queda_un_solo_activo_y_es_el_del_archivo(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'PRB-1', 'precio_unitario' => '2.00']);
        $viejo = $producto->preciosCliente()->create(['cliente_id' => $this->cliente->id, 'precio' => '1.00', 'activo' => false]);
        $vigente = $producto->preciosCliente()->create(['cliente_id' => $this->cliente->id, 'precio' => '1.10', 'activo' => true]);

        $this->importar($this->usuario(), $this->archivo('1.3000'))->assertSessionHas('resumen');

        $activos = ProductoPrecioCliente::where('producto_id', $producto->id)->where('activo', true)->get();
        $this->assertCount(1, $activos, 'Nunca dos precios activos para el mismo cliente y sala.');
        $this->assertSame('1.3000', $activos->sole()->precio);
        $this->assertSame('1.3000', $this->precioDelCcf($producto));
        $this->assertFalse($viejo->refresh()->activo);
        $this->assertSame($vigente->id, $activos->sole()->id, 'Se actualiza el que estaba vigente.');
    }

    /** Dos activos que ya venían repetidos: queda uno, con el precio del archivo. */
    public function test_dos_activos_repetidos_quedan_en_uno_con_el_precio_del_archivo(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'PRB-1']);
        $primero = $producto->preciosCliente()->create(['cliente_id' => $this->cliente->id, 'precio' => '1.00', 'activo' => true]);
        $segundo = $producto->preciosCliente()->create(['cliente_id' => $this->cliente->id, 'precio' => '1.10', 'activo' => true]);

        $this->importar($this->usuario(), $this->archivo('1.3000'));

        $this->assertFalse($primero->refresh()->activo);
        $this->assertTrue($segundo->refresh()->activo);
        $this->assertSame('1.3000', $segundo->precio);
        $this->assertSame('1.3000', $this->precioDelCcf($producto));
        $this->assertSame(1, Activity::where('log_name', 'precio_producto')->where('subject_id', $primero->id)
            ->where('description', 'actualizó un precio especial')->count(), 'El que se apaga queda en la bitácora.');
    }

    /**
     * Exportar, corregir en Excel y volver a subir el mismo archivo. El archivo trae también
     * los precios inactivos (una promo que ya terminó): su fila caía sobre el vigente y lo
     * dejaba con el precio y la fecha de la promo, y el CCF siguiente salía con ese.
     */
    public function test_volver_a_subir_el_archivo_exportado_no_cambia_el_precio_vigente_ni_su_fecha(): void
    {
        $admin = $this->usuario();
        $producto = Producto::factory()->create(['codigo' => 'PRB-1', 'nombre' => 'PRODUCTO DE PRUEBA', 'precio_unitario' => '2.00']);
        // Primero el que hoy vale y después la promo, ya inactiva: es el orden en que quedan al
        // volver de la promo (inactivar la promo y después activar el anterior).
        $vigente = $producto->preciosCliente()->create([
            'cliente_id' => $this->cliente->id, 'precio' => '1.25', 'activo' => true, 'fecha_inicio' => '2026-10-01',
        ]);
        $promo = $producto->preciosCliente()->create([
            'cliente_id' => $this->cliente->id, 'precio' => '1.10', 'activo' => false, 'fecha_inicio' => '2026-08-01',
        ]);

        $csv = $this->actingAs($admin)->get(route('importaciones.precios.exportar', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('1.1000,2026-08-01,No', $csv, 'El archivo exportado trae la promo inactiva.');

        $this->importar($admin, UploadedFile::fake()->createWithContent('precios.csv', $csv))
            ->assertSessionHas('resumen', fn (array $resumen) => $resumen['actualizadas'] === 1 && $resumen['ignoradas'] === 1
                && collect($resumen['detalles'])->contains(fn (array $d) => $d['accion'] === 'ignorado'
                    && str_contains($d['detalle'], 'Precio inactivo en el archivo')));

        $vigente->refresh();
        $this->assertTrue($vigente->activo);
        $this->assertSame('1.2500', $vigente->precio);
        $this->assertSame('2026-10-01', $vigente->fecha_inicio->toDateString(), 'La fecha del vigente no se cambia por la de la promo.');
        $promo->refresh();
        $this->assertFalse($promo->activo);
        $this->assertSame('1.1000', $promo->precio);
        $this->assertSame('1.2500', $this->precioDelCcf($producto));
    }

    public function test_no_toca_el_precio_de_otro_cliente_ni_el_de_una_sala(): void
    {
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id]);
        $producto = Producto::factory()->create(['codigo' => 'PRB-1']);
        $delOtro = $producto->preciosCliente()->create(['cliente_id' => $otro->id, 'precio' => '3.00', 'activo' => true]);
        $deLaSala = $producto->preciosCliente()->create([
            'cliente_id' => $this->cliente->id, 'cliente_sucursal_id' => $sala->id, 'precio' => '0.90', 'activo' => true,
        ]);

        $this->importar($this->usuario(), $this->archivo('1.0500'));

        $this->assertSame('3.0000', $delOtro->refresh()->precio);
        $this->assertTrue($delOtro->activo);
        $this->assertSame('0.9000', $deLaSala->refresh()->precio);
        $this->assertTrue($deLaSala->activo);
        $this->assertSame('0.9000', $this->precioDelCcf($producto, $sala->id), 'La sala conserva su precio propio.');
        $this->assertSame('1.0500', $this->precioDelCcf($producto));
    }

    public function test_un_rol_sin_importaciones_gestionar_recibe_403_y_no_cambia_nada(): void
    {
        foreach (['facturacion', 'jefatura', 'contabilidad'] as $rol) {
            $this->importar($this->usuario($rol), $this->archivo())->assertForbidden();
        }

        $this->assertSame(0, Producto::count());
        $this->assertSame(0, ProductoPrecioCliente::count());
    }

    public function test_sin_archivo_csv_o_sin_cliente_valido_no_importa_nada(): void
    {
        $admin = $this->usuario();
        $pdf = UploadedFile::fake()->create('precios.pdf', 10, 'application/pdf');

        $this->importar($admin, $pdf)->assertSessionHasErrors('archivo');
        $this->actingAs($admin)->from(route('importaciones.index'))
            ->post(route('importaciones.precios.importar'), ['cliente_id' => 999999, 'archivo' => $this->archivo()])
            ->assertSessionHasErrors('cliente_id');

        $this->assertSame(0, ProductoPrecioCliente::count());
    }
}
