<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Bitácora de los precios y del catálogo de exportación, por las rutas que usa la
 * oficina. Igual que en los precios nacionales, cada cambio queda con quién lo hizo
 * y qué valor había antes: quitar el precio de un cliente, cambiar el precio base,
 * finalizar una lista que cambia el precio del cliente, importar el Excel y archivar
 * un producto. Datos ficticios.
 */
class BitacoraPreciosExportacionTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    /** @return array{cliente: Cliente, perfil: ExportacionCliente} */
    private function clienteHabilitado(): array
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);

        return ['cliente' => $cliente, 'perfil' => $perfil];
    }

    private function presentacion(): ExportacionProducto
    {
        return app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'categoria' => null],
            ['unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        )->presentaciones()->sole();
    }

    /** El único registro de ese modelo y ese evento, hecho por el usuario de la prueba. */
    private function registro(string $logName, object $modelo, string $evento): Activity
    {
        $registro = Activity::query()
            ->where('log_name', $logName)
            ->where('subject_type', $modelo::class)
            ->where('subject_id', $modelo->getKey())
            ->where('event', $evento)
            ->sole();
        $this->assertSame($this->usuario->id, (int) $registro->causer_id, 'La bitácora tiene que decir quién lo hizo.');

        return $registro;
    }

    public function test_quitar_el_precio_de_un_cliente_queda_con_el_precio_que_tenia(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $this->presentacion()->id,
            'precio_caja' => 150.00, 'activo' => true,
        ]);

        $this->actingAs($this->usuario)
            ->delete(route('clientes.exportacion.productos.destroy', [$cliente, $asignacion]))
            ->assertSessionHas('status');

        $registro = $this->registro('precio_exportacion', $asignacion, 'deleted');
        $this->assertSame('eliminó un precio de exportación', $registro->description);
        $this->assertEquals(150.00, (float) $registro->properties['old']['precio_caja']);
        $this->assertEquals($perfil->id, $registro->properties['old']['exportacion_cliente_id']);
    }

    public function test_cambiar_el_precio_base_de_una_presentacion_queda_con_el_valor_anterior(): void
    {
        $producto = $this->presentacion();

        $this->actingAs($this->usuario)->put(route('productos.exportacion.update', $producto), [
            'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85,
            'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => '', 'precio_caja' => 150,
        ])->assertSessionHasNoErrors();

        $registro = $this->registro('producto_exportacion', $producto, 'updated');
        $this->assertEquals(136.80, (float) $registro->properties['old']['precio_caja']);
        $this->assertEquals(150.00, (float) $registro->properties['attributes']['precio_caja']);
        $this->assertEquals(['precio_caja'], array_keys($registro->properties['attributes']), 'Solo se anota lo que cambió.');
    }

    public function test_finalizar_una_lista_que_cambia_el_precio_del_cliente_queda_en_la_bitacora(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $producto = $this->presentacion();
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id,
            'precio_caja' => 140.00, 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-20', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create(['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => 150.00] + $producto->datosSnapshot());
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);
        $fex = Dte::create([
            'tipo_dte' => TipoDte::FacturaExportacion->value, 'estado' => 'generado', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-11-M001P001-000000000000001',
            'fecha_emision' => '2026-11-20', 'hora_emision' => '10:00:00', 'total_pagar' => 1500.00,
        ]);
        DB::table('exportacion_dte')->insert(['exportacion_id' => $lista->id, 'dte_id' => $fex->id, 'principal' => true, 'created_at' => now(), 'updated_at' => now()]);
        $lista->update(['dte_id' => $fex->id]);

        $this->actingAs($this->usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('status');

        $registro = $this->registro('precio_exportacion', $asignacion, 'updated');
        $this->assertEquals(140.00, (float) $registro->properties['old']['precio_caja']);
        $this->assertEquals(150.00, (float) $registro->properties['attributes']['precio_caja']);
        $this->assertEquals($lista->id, $registro->properties['attributes']['precio_desde_exportacion_id']);
    }

    public function test_cada_presentacion_que_crea_el_excel_queda_en_la_bitacora(): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Lista');
        $hoja->setCellValue('C8', 'Descripción');
        foreach (['B' => 1, 'C' => 'Producto de prueba \\ Test product', 'D' => 'Bolsa de prueba', 'E' => 144, 'F' => 85, 'J' => 136.80, 'L' => 13, 'M' => 14] as $columna => $valor) {
            $hoja->setCellValue($columna.'9', $valor);
        }
        $hoja->setCellValue('B10', '=SUM(E9:E9)');
        $ruta = tempnam(sys_get_temp_dir(), 'catalogo_prueba_').'.xlsx';
        $this->temporales[] = $ruta;
        (new Xlsx($libro))->save($ruta);

        $this->actingAs($this->usuario)->post(route('productos.exportacion.importar.run'), [
            'archivo' => new UploadedFile($ruta, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertSessionHas('status');

        $registro = $this->registro('producto_exportacion', ExportacionProducto::query()->sole(), 'created');
        $this->assertSame('Producto de prueba', $registro->properties['attributes']['nombre_es']);
        $this->assertEquals(136.80, (float) $registro->properties['attributes']['precio_caja']);
    }

    public function test_archivar_el_producto_base_queda_en_la_bitacora(): void
    {
        $base = $this->presentacion()->base;

        $this->actingAs($this->usuario)->patch(route('productos.exportacion.base.toggle-activo', $base))->assertSessionHas('status');

        $registro = $this->registro('producto_exportacion', $base, 'updated');
        $this->assertInstanceOf(ExportacionProductoBase::class, $registro->subject);
        $this->assertEquals(['activo' => true], $registro->properties['old']);
        $this->assertEquals(['activo' => false], $registro->properties['attributes']);
    }
}
