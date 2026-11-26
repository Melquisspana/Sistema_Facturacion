<?php

namespace Tests\Feature\Productos;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\ExportacionProductoBase;
use App\Models\User;
use App\Services\Exportaciones\CatalogoExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Productos › «De exportación»: editar una presentación e importar el catálogo desde el
 * Excel, por sus rutas (productos.exportacion.update y productos.exportacion.importar.run).
 *
 * Editar cambia solo el catálogo: las listas de empaque ya hechas guardan su copia y los
 * precios de cada cliente no se tocan. Importar crea lo nuevo, cuenta lo que ya existía
 * sin pisarlo y no deja pasar lo que el formulario tampoco deja (cajas sin unidades,
 * precios negativos). Datos ficticios.
 */
class ProductosExportacionRutasTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function base(string $nombre = 'Producto de prueba', array $presentacion = []): ExportacionProductoBase
    {
        return app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => $nombre, 'nombre_en' => 'Test product', 'categoria' => null],
            $presentacion + ['codigo' => 'PP-1', 'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        );
    }

    /** Lo que manda el formulario «Editar presentación» (no lleva código ni «activo»). */
    private function formulario(array $cambios = []): array
    {
        return $cambios + [
            'unidad' => 'Caja de prueba',
            'unidades_por_caja' => 120,
            'gramos_por_unidad' => 100,
            'peso_neto_caja_kg' => 12,
            'peso_bruto_caja_kg' => '',
            'precio_caja' => 150,
        ];
    }

    private function datosDe(ExportacionProducto $p): array
    {
        return collect($p->fresh()->getAttributes())->except(['updated_at'])->all();
    }

    // ------------------------------------------------------------------ editar

    public function test_editar_una_presentacion_cambia_el_catalogo_calcula_los_pesos_y_no_toca_listas_ni_precios_de_clientes(): void
    {
        $base = $this->base();
        $presentacion = $base->presentaciones()->sole();

        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
        $precioCliente = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $presentacion->id, 'precio_caja' => 140.00, 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $linea = $lista->items()->create([
            'exportacion_producto_id' => $presentacion->id, 'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product',
            'unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 140.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00, 'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14,
            'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86,
        ]);
        $lineaAntes = $linea->fresh()->getAttributes();
        $precioAntes = $precioCliente->fresh()->getAttributes();

        $this->actingAs($this->usuario())->put(route('productos.exportacion.update', $presentacion), $this->formulario())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('productos.exportacion.index').'#producto-'.$base->id)
            ->assertSessionHas('status');

        $presentacion->refresh();
        $this->assertSame('Caja de prueba', $presentacion->unidad);
        $this->assertSame(120, (int) $presentacion->unidades_por_caja);
        $this->assertEquals(100.0, (float) $presentacion->gramos_por_unidad);
        $this->assertEquals(150.0, (float) $presentacion->precio_caja);
        // Calculados: 100 g = 3,53 oz; 12 kg = 26,46 lb; bruto vacío = neto + 1 kg = 13 kg = 28,66 lb.
        $this->assertEquals(3.53, (float) $presentacion->onzas_por_unidad);
        $this->assertEquals(12.0, (float) $presentacion->peso_neto_caja_kg);
        $this->assertEquals(13.0, (float) $presentacion->peso_bruto_caja_kg);
        $this->assertEquals(26.46, (float) $presentacion->peso_neto_caja_lb);
        $this->assertEquals(28.66, (float) $presentacion->peso_bruto_caja_lb);
        // El formulario no manda código ni «activo»: se conservan.
        $this->assertSame('PP-1', $presentacion->codigo);
        $this->assertTrue($presentacion->activo);

        $this->assertEquals($lineaAntes, $linea->fresh()->getAttributes());
        $this->assertEquals($precioAntes, $precioCliente->fresh()->getAttributes());
    }

    public function test_guardar_dos_veces_deja_lo_mismo_y_no_crea_presentaciones(): void
    {
        $presentacion = $this->base()->presentaciones()->sole();
        $usuario = $this->usuario();

        $this->actingAs($usuario)->put(route('productos.exportacion.update', $presentacion), $this->formulario())->assertSessionHasNoErrors();
        $despuesDeUna = $this->datosDe($presentacion);
        $this->actingAs($usuario)->put(route('productos.exportacion.update', $presentacion), $this->formulario())->assertSessionHasNoErrors();

        $this->assertEquals($despuesDeUna, $this->datosDe($presentacion));
        $this->assertSame(1, ExportacionProducto::count());
    }

    public function test_una_presentacion_repetida_en_el_mismo_producto_da_error_y_no_cambia_nada(): void
    {
        $base = $this->base();
        $segunda = app(CatalogoExportacion::class)->agregarPresentacion($base, [
            'unidad' => 'Caja de prueba', 'unidades_por_caja' => 120, 'gramos_por_unidad' => 100, 'peso_neto_caja_kg' => 12,
        ]);
        $antes = $this->datosDe($segunda);

        // 144 × 85 g ya la tiene la primera presentación de este producto.
        $this->actingAs($this->usuario())
            ->put(route('productos.exportacion.update', $segunda), $this->formulario(['unidades_por_caja' => 144, 'gramos_por_unidad' => 85]))
            ->assertSessionHasErrors('unidades_por_caja');

        $this->assertEquals($antes, $this->datosDe($segunda));

        // En OTRO producto, la misma presentación sí se permite.
        $otra = $this->base('Otro producto de prueba', ['unidades_por_caja' => 120, 'gramos_por_unidad' => 100])->presentaciones()->sole();
        $this->actingAs($this->usuario())
            ->put(route('productos.exportacion.update', $otra), $this->formulario(['unidades_por_caja' => 144, 'gramos_por_unidad' => 85]))
            ->assertSessionHasNoErrors();
    }

    public function test_pesos_unidades_o_precio_imposibles_no_se_guardan(): void
    {
        $presentacion = $this->base()->presentaciones()->sole();
        $antes = $this->datosDe($presentacion);
        $usuario = $this->usuario();

        foreach ([
            'peso_bruto_caja_kg' => ['peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 11],
            'unidades_por_caja' => ['unidades_por_caja' => 0],
            'gramos_por_unidad' => ['gramos_por_unidad' => 0],
            'precio_caja' => ['precio_caja' => -1],
        ] as $campo => $cambio) {
            $this->actingAs($usuario)->put(route('productos.exportacion.update', $presentacion), $this->formulario($cambio))
                ->assertSessionHasErrors($campo);
        }

        $this->assertEquals($antes, $this->datosDe($presentacion));
    }

    public function test_editar_una_presentacion_archivada_no_la_reactiva(): void
    {
        $presentacion = $this->base()->presentaciones()->sole();
        $presentacion->update(['activo' => false]);

        $this->actingAs($this->usuario())->put(route('productos.exportacion.update', $presentacion), $this->formulario())
            ->assertSessionHasNoErrors();

        $this->assertFalse($presentacion->fresh()->activo);
    }

    public function test_editar_sin_permiso_da_403_y_una_presentacion_que_no_existe_404(): void
    {
        $presentacion = $this->base()->presentaciones()->sole();
        $antes = $this->datosDe($presentacion);

        foreach (['jefatura', 'produccion'] as $rol) {
            $this->actingAs($this->usuario($rol))->put(route('productos.exportacion.update', $presentacion), $this->formulario())->assertForbidden();
        }
        $this->actingAs($this->usuario())->put(route('productos.exportacion.update', 999999), $this->formulario())->assertNotFound();

        $this->assertEquals($antes, $this->datosDe($presentacion));
    }

    // ---------------------------------------------------------------- importar

    /**
     * Excel con el formato de la hoja «Lista»: encabezados en la fila 8, productos desde la
     * 9 y la fila de totales al final.
     *
     * @param  list<array{0: string, 1: int, 2: float, 3: float, 4: float}>  $filas  descripción, unidades, gramos, precio, neto
     */
    private function excel(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Lista');
        $hoja->setCellValue('C8', 'Descripción');
        $fila = 9;
        foreach ($filas as $i => [$descripcion, $unidades, $gramos, $precio, $neto]) {
            $hoja->setCellValue('B'.$fila, $i + 1);
            $hoja->setCellValue('C'.$fila, $descripcion);
            $hoja->setCellValue('D'.$fila, 'Bolsa de prueba');
            $hoja->setCellValue('E'.$fila, $unidades);
            $hoja->setCellValue('F'.$fila, $gramos);
            $hoja->setCellValue('H'.$fila, round($gramos * 0.035274, 2));
            $hoja->setCellValue('J'.$fila, $precio);
            $hoja->setCellValue('L'.$fila, $neto);
            $hoja->setCellValue('M'.$fila, $neto + 1);
            $hoja->setCellValue('Q'.$fila, round($neto * 2.20462, 2));
            $hoja->setCellValue('R'.$fila, round(($neto + 1) * 2.20462, 2));
            $fila++;
        }
        $hoja->setCellValue('B'.$fila, '=SUM(E9:E'.($fila - 1).')');

        $ruta = tempnam(sys_get_temp_dir(), 'catalogo_prueba_').'.xlsx';
        $this->temporales[] = $ruta;
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_importar_crea_lo_nuevo_cuenta_lo_que_ya_existia_sin_pisarlo_y_repetirlo_no_duplica(): void
    {
        $existente = $this->base('Producto existente de prueba')->presentaciones()->sole();
        $antes = $this->datosDe($existente);
        $usuario = $this->usuario();
        // El existente viene con la presentación que ya tiene (144 u de 85 g) y otro precio
        // y otros pesos: se omite sin pisarlo. Con otra presentación se le agregaría
        // (ImportarCatalogoExportacionBordesTest).
        $filas = [
            ['Producto nuevo de prueba \\ New test product', 144, 85, 136.80, 13],
            ['Producto existente de prueba \\ Existing test product', 144, 85, 999.00, 5],
        ];

        $this->actingAs($usuario)->post(route('productos.exportacion.importar.run'), ['archivo' => $this->excel($filas)])
            ->assertRedirect(route('productos.exportacion.index'))
            ->assertSessionHas('status', 'Importación completada: 1 creados, 1 omitidos (ya existían).');

        $nuevo = ExportacionProducto::query()->where('nombre_es', 'Producto nuevo de prueba')->sole();
        $this->assertSame('New test product', $nuevo->nombre_en);
        $this->assertSame(144, (int) $nuevo->unidades_por_caja);
        $this->assertEquals(136.80, (float) $nuevo->precio_caja);
        $this->assertEquals(13.0, (float) $nuevo->peso_neto_caja_kg);
        $this->assertTrue($nuevo->activo);
        $this->assertEquals($antes, $this->datosDe($existente));

        // El mismo archivo otra vez (doble clic): nada nuevo.
        $this->actingAs($usuario)->post(route('productos.exportacion.importar.run'), ['archivo' => $this->excel($filas)])
            ->assertSessionHas('status', 'Importación completada: 0 creados, 2 omitidos (ya existían).');
        $this->assertSame(2, ExportacionProducto::count());
    }

    /**
     * Lo que el formulario no deja guardar tampoco entra por el Excel: una caja sin
     * unidades o un precio negativo terminarían en una lista de empaque y en su FEX.
     */
    public function test_importar_no_crea_filas_con_unidades_pesos_o_precio_imposibles_y_las_informa(): void
    {
        $filas = [
            ['Producto bueno de prueba \\ Good test product', 144, 85, 136.80, 13],
            ['Caja sin unidades de prueba \\ No units', 0, 85, 136.80, 13],
            ['Precio negativo de prueba \\ Negative price', 144, 85, -10, 13],
            ['Sin gramos de prueba \\ No grams', 144, 0, 136.80, 13],
            ['Sin peso de prueba \\ No weight', 144, 85, 136.80, 0],
        ];

        $respuesta = $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.importar.run'), ['archivo' => $this->excel($filas)])
            ->assertRedirect(route('productos.exportacion.index'))
            ->assertSessionHas('error');

        $this->assertSame(['Producto bueno de prueba'], ExportacionProducto::query()->pluck('nombre_es')->all());
        $mensaje = (string) session('error');
        $this->assertStringContainsString('Importación completada: 1 creados', $mensaje);
        foreach (['Fila 10', 'Fila 11', 'Fila 12', 'Fila 13'] as $fila) {
            $this->assertStringContainsString($fila, $mensaje);
        }
        $respuesta->assertSessionMissing('status');
    }

    public function test_un_archivo_que_no_es_excel_da_error_y_no_crea_nada(): void
    {
        // Archivo de verdad (no el falso de Laravel, que cree en la extensión): el tipo
        // se mira por su contenido, como en el servidor.
        $ruta = tempnam(sys_get_temp_dir(), 'no_excel_').'.xlsx';
        $this->temporales[] = $ruta;
        file_put_contents($ruta, 'esto no es un Excel');

        $this->actingAs($this->usuario())
            ->post(route('productos.exportacion.importar.run'), ['archivo' => new UploadedFile($ruta, 'catalogo.xlsx', null, null, true)])
            ->assertSessionHasErrors('archivo');

        $this->assertSame(0, ExportacionProducto::count());
    }

    public function test_sin_archivo_ni_catalogo_guardado_en_el_servidor_avisa_y_no_crea_nada(): void
    {
        config(['exportaciones.plantilla' => 'templates/exportaciones/no-existe-'.uniqid().'.xlsx']);

        $this->actingAs($this->usuario())
            ->from(route('productos.exportacion.importar'))
            ->post(route('productos.exportacion.importar.run'))
            ->assertRedirect(route('productos.exportacion.importar'))
            ->assertSessionHas('error');

        $this->assertSame(0, ExportacionProducto::count());
    }

    public function test_importar_sin_permiso_da_403_y_no_crea_nada(): void
    {
        foreach (['jefatura', 'produccion'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('productos.exportacion.importar.run'), ['archivo' => $this->excel([['Producto de prueba \\ Test', 144, 85, 1, 13]])])
                ->assertForbidden();
        }

        $this->assertSame(0, ExportacionProducto::count());
    }
}
