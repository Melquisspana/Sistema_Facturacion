<?php

namespace Tests\Feature\Productos;

use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

/**
 * Productos › «De exportación» › Importar Excel: los bordes que la oficina puede
 * provocar sin querer. Un peso bruto menor que el neto (el formulario no lo deja), el
 * mismo producto dos veces en el archivo y un Excel dañado que igual se llama .xlsx.
 * En ningún caso se crea algo a medias ni se pisa lo que había. Datos ficticios.
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
     * @param  list<array{0: string, 1: int, 2: float, 3: float, 4: float, 5: float}>  $filas  descripción, unidades, gramos, precio, neto, bruto
     */
    private function rutaExcel(array $filas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Lista');
        $hoja->setCellValue('C8', 'Descripción');
        $fila = 9;
        foreach ($filas as $i => [$descripcion, $unidades, $gramos, $precio, $neto, $bruto]) {
            $hoja->setCellValue('B'.$fila, $i + 1);
            $hoja->setCellValue('C'.$fila, $descripcion);
            $hoja->setCellValue('D'.$fila, 'Bolsa de prueba');
            $hoja->setCellValue('E'.$fila, $unidades);
            $hoja->setCellValue('F'.$fila, $gramos);
            $hoja->setCellValue('H'.$fila, round($gramos * 0.035274, 2));
            $hoja->setCellValue('J'.$fila, $precio);
            $hoja->setCellValue('L'.$fila, $neto);
            $hoja->setCellValue('M'.$fila, $bruto);
            $hoja->setCellValue('Q'.$fila, round($neto * 2.20462, 2));
            $hoja->setCellValue('R'.$fila, round($bruto * 2.20462, 2));
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

    /** El mismo producto dos veces en el archivo: entra el primero y el segundo no lo pisa. */
    public function test_el_mismo_producto_dos_veces_en_el_archivo_entra_una_sola_vez_sin_pisarse(): void
    {
        $ruta = $this->rutaExcel([
            ['Producto de prueba \\ Test product', 144, 85, 136.80, 13, 14],
            ['Producto de prueba \\ Test product', 24, 40, 99.00, 5, 6],
        ]);

        $this->actingAs($this->usuario())->post(route('productos.exportacion.importar.run'), ['archivo' => $this->subir($ruta)])
            ->assertSessionHas('status', 'Importación completada: 1 creados, 1 omitidos (ya existían).');

        $producto = ExportacionProducto::query()->sole();
        $this->assertSame(144, (int) $producto->unidades_por_caja);
        $this->assertEquals(136.80, (float) $producto->precio_caja);
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
