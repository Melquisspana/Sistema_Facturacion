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
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use ZipArchive;

/**
 * Productos › «De exportación» › Importar Excel: los bordes que la oficina puede
 * provocar sin querer. Un peso bruto menor que el neto (el formulario no lo deja), el
 * mismo producto dos veces en el archivo —con la misma presentación o con otra— y un
 * Excel dañado que igual se llama .xlsx. En ningún caso se crea algo a medias ni se
 * pisa lo que había. Datos ficticios.
 */
class ImportarCatalogoExportacionBordesTest extends TestCase
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

    private function usuario(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('facturacion');
    }

    /**
     * Excel con el formato de la hoja «Lista»: encabezados en la fila 8 y productos desde la 9.
     *
     * El 7.º elemento, opcional, cambia celdas sueltas de la fila (null = celda vacía),
     * por ejemplo ['D' => null] para una fila sin empaque.
     *
     * @param  list<array{0: string, 1: int, 2: float, 3: float, 4: float, 5: float, 6?: array<string, mixed>}>  $filas  descripción, unidades, gramos, precio, neto, bruto
     */
    private function rutaExcel(array $filas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Lista');
        $hoja->setCellValue('C8', 'Descripción');
        $fila = 9;
        foreach ($filas as $i => $datos) {
            [$descripcion, $unidades, $gramos, $precio, $neto, $bruto] = $datos;
            $celdas = ($datos[6] ?? []) + [
                'B' => $i + 1,
                'C' => $descripcion,
                'D' => 'Bolsa de prueba',
                'E' => $unidades,
                'F' => $gramos,
                'H' => round($gramos * 0.035274, 2),
                'J' => $precio,
                'L' => $neto,
                'M' => $bruto,
                'Q' => round($neto * 2.20462, 2),
                'R' => round($bruto * 2.20462, 2),
            ];
            foreach ($celdas as $columna => $valor) {
                if ($valor !== null) {
                    $hoja->setCellValue($columna.$fila, $valor);
                }
            }
            $fila++;
        }
        $hoja->setCellValue('B'.$fila, '=SUM(E9:E'.($fila - 1).')');

        $ruta = tempnam(sys_get_temp_dir(), 'catalogo_prueba_').'.xlsx';
        $this->temporales[] = $ruta;
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }

    private function subir(string $ruta): UploadedFile
    {
        return new UploadedFile($ruta, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_una_fila_con_peso_bruto_menor_que_el_neto_no_se_crea_y_se_informa(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto bueno de prueba \\ Good test product', 144, 85, 136.80, 13, 14],
            ['Bruto menor de prueba \\ Lower gross', 144, 85, 136.80, 13, 5],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertRedirect(route('productos.exportacion.index'))
            ->assertSessionHas('error');

        $this->assertSame(['Producto bueno de prueba'], ExportacionProducto::query()->pluck('nombre_es')->all());
        $mensaje = (string) session('error');
        $this->assertStringContainsString('Importación completada: 1 creados', $mensaje);
        $this->assertStringContainsString('Fila 10', $mensaje);
        $this->assertStringContainsString('peso bruto', $mensaje);
    }

    /**
     * Onzas y libras se calculan de gramos y kilos, igual que en el formulario: una fila
     * con las onzas vacías y las libras en 0 o mal tecleadas no deja una caja con 0 oz ni
     * con el bruto en libras menor que el neto. Sin peso bruto, la caja pesa neto + 1 kg.
     */
    public function test_onzas_y_libras_salen_de_gramos_y_kilos_aunque_el_excel_las_traiga_mal(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14, ['H' => null, 'Q' => 2.86, 'R' => 0]],
            ['Otro producto de prueba \\ Other test product', 24, 40, 99.00, 5, 0, ['M' => null, 'R' => null]],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('status', 'Importación completada: 2 creados, 0 omitidos (ya existían).');

        $producto = ExportacionProducto::query()->where('nombre_es', 'Producto de prueba')->sole();
        $this->assertEquals(3.00, (float) $producto->onzas_por_unidad);
        $this->assertEquals(14.00, (float) $producto->peso_bruto_caja_kg);
        $this->assertEquals(28.66, (float) $producto->peso_neto_caja_lb);
        $this->assertEquals(30.86, (float) $producto->peso_bruto_caja_lb);

        $sinBruto = ExportacionProducto::query()->where('nombre_es', 'Otro producto de prueba')->sole();
        $this->assertEquals(6.00, (float) $sinBruto->peso_bruto_caja_kg);
        $this->assertEquals(13.23, (float) $sinBruto->peso_bruto_caja_lb);
    }

    /** El empaque es obligatorio en el formulario: una fila sin columna D no se crea y se informa. */
    public function test_una_fila_sin_empaque_no_se_crea_y_se_informa(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14],
            ['Sin empaque de prueba \\ No packaging', 144, 85, 136.80, 13, 14, ['D' => null]],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertRedirect(route('productos.exportacion.index'))
            ->assertSessionHas('error');

        $this->assertSame(['Producto de prueba'], ExportacionProducto::query()->pluck('nombre_es')->all());
        $mensaje = (string) session('error');
        $this->assertStringContainsString('Importación completada: 1 creados', $mensaje);
        $this->assertStringContainsString('Fila 10', $mensaje);
        $this->assertStringContainsString('empaque', $mensaje);
    }

    /**
     * La misma presentación dos veces en el archivo (mismas unidades por caja y mismos
     * gramos): entra la primera y la segunda no la pisa.
     */
    public function test_la_misma_presentacion_dos_veces_en_el_archivo_entra_una_sola_vez_sin_pisarse(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14],
            ['Producto de prueba \\ Test product', 144, 85, 99.00, 5, 6],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('status', 'Importación completada: 1 creados, 1 omitidos (ya existían).');

        $producto = ExportacionProducto::query()->sole();
        $this->assertSame(144, (int) $producto->unidades_por_caja);
        $this->assertEquals(136.80, (float) $producto->precio_caja);
        $this->assertEquals(13.00, (float) $producto->peso_neto_caja_kg);
    }

    /**
     * Decisión de Melqui: el mismo producto con otra presentación (otras unidades por
     * caja u otros gramos) se le agrega como presentación, sin crear otro producto y
     * sin pisar la que ya tenía. Onzas y libras salen de gramos y kilos, como siempre.
     * El resumen lo dice en una línea por fila. Las tres quedan bajo un solo producto
     * base: antes quedaban tres filas sueltas con el mismo nombre en «Sin agrupar».
     */
    public function test_el_mismo_producto_con_otra_presentacion_se_le_agrega_y_el_resumen_lo_dice(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14],
            ['Producto de prueba \\ Test product', 24, 40, 99.00, 5, 0, ['M' => null]],
            ['Producto de prueba \\ Test product', 144, 90, 140.00, 14, 15],
            ['Producto de prueba \\ Test product', 144, 85, 150.00, 20, 21],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('status', "Importación completada: 1 creados, 1 omitidos (ya existían).\n"
                ."Fila 10: se agregó la presentación Bolsa de prueba · 24 u, 40 g a «Producto de prueba».\n"
                .'Fila 11: se agregó la presentación Bolsa de prueba · 144 u, 90 g a «Producto de prueba».');

        $presentaciones = ExportacionProducto::query()->orderBy('id')->get();
        $this->assertSame(['Producto de prueba', 'Producto de prueba', 'Producto de prueba'], $presentaciones->pluck('nombre_es')->all());
        $this->assertSame([144, 24, 144], $presentaciones->pluck('unidades_por_caja')->all());
        // La fila 12 repite la primera presentación: no la pisa.
        $this->assertEquals(136.80, (float) $presentaciones[0]->precio_caja);

        $nueva = $presentaciones[1];
        $this->assertEquals(40.00, (float) $nueva->gramos_por_unidad);
        $this->assertEquals(1.41, (float) $nueva->onzas_por_unidad);
        $this->assertEquals(5.00, (float) $nueva->peso_neto_caja_kg);
        $this->assertEquals(6.00, (float) $nueva->peso_bruto_caja_kg);
        $this->assertEquals(11.02, (float) $nueva->peso_neto_caja_lb);
        $this->assertEquals(13.23, (float) $nueva->peso_bruto_caja_lb);
        $this->assertEquals(99.00, (float) $nueva->precio_caja);

        $base = ExportacionProductoBase::query()->sole();
        $this->assertSame(['Producto de prueba', 'Test product'], [$base->nombre_es, $base->nombre_en]);
        $this->assertSame([$base->id, $base->id, $base->id], $presentaciones->pluck('exportacion_producto_base_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Un producto suelto (sin producto base) que dejó una importación anterior, con su
     * precio de cliente y una lista que lo usa: la presentación nueva no se agrega como
     * otra fila suelta con el mismo nombre. Se le crea su producto base, la fila que ya
     * estaba pasa a ser una presentación de él —la misma, con sus precios y sus listas—
     * y la nueva cuelga de él. Productos › De exportación lo muestra agrupado y la
     * bitácora dice quién creó el producto, quién agrupó la fila y quién agregó la nueva.
     */
    public function test_a_un_producto_suelto_se_le_crea_su_producto_base_y_la_presentacion_nueva_cuelga_de_el(): void
    {
        $usuario = $this->usuario();
        $this->actingAs($usuario)->post(route('productos.exportacion.importar.run'), [
            'archivo' => $this->subir($this->rutaExcel([['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14]])),
        ])->assertSessionHas('status', 'Importación completada: 1 creados, 0 omitidos (ya existían).');
        $suelta = ExportacionProducto::query()->sole();
        $this->assertNull($suelta->exportacion_producto_base_id);
        $perfil = ExportacionCliente::create([
            'cliente_id' => Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba'])->id,
            'nombre' => 'Cliente de prueba', 'activo' => true,
        ]);
        $precio = ExportacionClienteProducto::create(['exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $suelta->id, 'precio_caja' => 140.00, 'precio_fijado_en' => '2026-11-01', 'activo' => true]);
        $lista = Exportacion::create(['exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba', 'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-01', 'estado' => Exportacion::ESTADO_BORRADOR]);
        $item = $lista->items()->create(['exportacion_producto_id' => $suelta->id, 'cantidad_cajas' => 10, 'precio_caja' => 140.00] + $suelta->datosSnapshot());

        $this->actingAs($usuario)->post(route('productos.exportacion.importar.run'), [
            'archivo' => $this->subir($this->rutaExcel([['Producto de prueba \\ Test product', 24, 40, 99.00, 5, 6]])),
        ])->assertSessionHas('status', "Importación completada: 0 creados, 0 omitidos (ya existían).\n"
            .'Fila 9: se agregó la presentación Bolsa de prueba · 24 u, 40 g a «Producto de prueba».');

        $base = ExportacionProductoBase::query()->sole();
        $this->assertSame(['Producto de prueba', 'Test product'], [$base->nombre_es, $base->nombre_en]);
        $this->assertTrue($base->activo);
        $this->assertSame([144, 24], $base->presentaciones()->orderBy('id')->pluck('unidades_por_caja')->all());
        $this->assertSame(2, ExportacionProducto::count());
        // La que ya estaba es la misma fila: su precio de cliente y su lista siguen con ella.
        $this->assertSame($base->id, (int) $suelta->fresh()->exportacion_producto_base_id);
        $this->assertSame($suelta->id, (int) $precio->fresh()->exportacion_producto_id);
        $this->assertSame($suelta->id, (int) $item->fresh()->exportacion_producto_id);

        $indice = $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertOk();
        $this->assertCount(0, $indice->viewData('sinAgrupar'));

        $bitacora = Activity::query()->where('log_name', 'producto_exportacion')->where('causer_id', $usuario->id);
        $this->assertSame(1, (clone $bitacora)->where('subject_type', ExportacionProductoBase::class)->where('subject_id', $base->id)->where('event', 'created')->count());
        $agrupada = (clone $bitacora)->where('subject_type', ExportacionProducto::class)->where('subject_id', $suelta->id)->where('event', 'updated')->sole();
        $this->assertSame($base->id, (int) $agrupada->properties['attributes']['exportacion_producto_base_id']);
        $nueva = ExportacionProducto::query()->where('unidades_por_caja', 24)->sole();
        $this->assertSame(1, (clone $bitacora)->where('subject_type', ExportacionProducto::class)->where('subject_id', $nueva->id)->where('event', 'created')->count());
    }

    /**
     * Intento de romperlo: dos filas sueltas del mismo producto con la misma presentación
     * (una se editó en Productos hasta quedar igual a la otra). Agruparlas dejaría la
     * presentación repetida dentro del producto, que es justo lo que no se permite: la
     * fila se informa como error y no cambia nada.
     */
    public function test_un_producto_suelto_con_una_presentacion_repetida_no_se_agrupa_y_se_informa(): void
    {
        foreach ([136.80, 140.00] as $precio) {
            ExportacionProducto::create([
                'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa de prueba',
                'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => $precio,
                'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14, 'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86, 'activo' => true,
            ]);
        }

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), [
            'archivo' => $this->subir($this->rutaExcel([['Producto de prueba \\ Test product', 24, 40, 99.00, 5, 6]])),
        ])->assertSessionHas('error');

        $mensaje = (string) session('error');
        $this->assertStringContainsString('Fila 9', $mensaje);
        $this->assertStringContainsString('repetida', $mensaje);
        $this->assertStringNotContainsString('Fila 9: se agregó', $mensaje);
        $this->assertSame(0, ExportacionProductoBase::count());
        $this->assertSame([144, 144], ExportacionProducto::query()->pluck('unidades_por_caja')->all());
        $this->assertSame([null, null], ExportacionProducto::query()->pluck('exportacion_producto_base_id')->all());
    }

    /**
     * Un producto del catálogo, ya con su producto base: la presentación nueva cuelga de
     * ese mismo producto (no se crea otro), lleva sus nombres y queda en la bitácora con
     * quién la importó, igual que un alta.
     */
    public function test_la_presentacion_nueva_de_un_producto_del_catalogo_cuelga_de_el_y_queda_en_la_bitacora(): void
    {
        $base = app(CatalogoExportacion::class)->crearProducto(
            ['nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'categoria' => null],
            ['unidad' => 'Bolsa de prueba', 'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'peso_neto_caja_kg' => 13, 'precio_caja' => 136.80],
        );
        $usuario = $this->usuario();
        $ruta = $this->rutaExcel([['Producto de prueba \\ Otro nombre en inglés', 216, 85, 200.00, 19, 20]]);

        $this->actingAs($usuario)->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('status', "Importación completada: 0 creados, 0 omitidos (ya existían).\n"
                .'Fila 9: se agregó la presentación Bolsa de prueba · 216 u, 85 g a «Producto de prueba».');

        $this->assertSame(1, ExportacionProductoBase::count());
        $nueva = ExportacionProducto::query()->where('unidades_por_caja', 216)->sole();
        $this->assertSame($base->id, (int) $nueva->exportacion_producto_base_id);
        $this->assertSame('Test product', $nueva->nombre_en);
        $this->assertEquals(41.89, (float) $nueva->peso_neto_caja_lb);

        $registro = Activity::query()->where('log_name', 'producto_exportacion')
            ->where('subject_type', ExportacionProducto::class)->where('subject_id', $nueva->id)->where('event', 'created')->sole();
        $this->assertSame($usuario->id, (int) $registro->causer_id);
        $this->assertSame('creó una presentación de exportación', $registro->description);
    }

    /** La presentación nueva pasa por las mismas reglas que un alta: con un dato imposible no se crea y se informa. */
    public function test_una_presentacion_nueva_con_datos_imposibles_no_se_agrega_y_se_informa(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14],
            ['Producto de prueba \\ Test product', 24, 40, 99.00, 5, 3],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('error');

        $this->assertSame([144], ExportacionProducto::query()->pluck('unidades_por_caja')->all());
        $mensaje = (string) session('error');
        $this->assertStringContainsString('Importación completada: 1 creados, 0 omitidos', $mensaje);
        $this->assertStringContainsString('Fila 10', $mensaje);
        $this->assertStringContainsString('peso bruto', $mensaje);
        $this->assertStringNotContainsString('se agregó', $mensaje);
    }

    /**
     * Un Excel dañado (se cortó la descarga, se guardó mal) que igual se llama .xlsx y
     * pasa el control del tipo de archivo: tiene que volver con un aviso, no con una
     * página de error, y no crear nada.
     */
    public function test_un_excel_danado_vuelve_con_un_aviso_y_no_crea_nada(): void
    {
        $ruta = $this->rutaExcel([['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14]]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $zip->addFromString('xl/workbook.xml', 'esto no es el libro');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row');
        $zip->close();

        $this->actingAs($this->usuario())
            ->from(route('productos.exportacion.importar'))
            ->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertRedirect(route('productos.exportacion.importar'))
            ->assertSessionHas('error');

        $this->assertSame(0, ExportacionProducto::count());
    }
}
