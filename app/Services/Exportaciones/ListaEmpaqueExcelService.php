<?php

namespace App\Services\Exportaciones;

use App\Models\Exportacion;
use App\Models\ExportacionItem;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Genera el Excel de Lista de Empaque a partir de la PLANTILLA oficial
 * (storage/app/templates/exportaciones/lista_empaque.xlsx), conservando el
 * diseño: bordes, anchos, formatos de número y celdas combinadas.
 *
 * Solo usa la hoja "Lista"; cualquier otra hoja de la plantilla (p. ej.
 * "Factura") se descarta del archivo generado — fuera del alcance del módulo.
 */
class ListaEmpaqueExcelService
{
    /** Layout de la hoja "Lista" de la plantilla (ver análisis de la plantilla). */
    private const FILA_PRIMER_PRODUCTO = 9;
    private const FILAS_PRODUCTO_PLANTILLA = 24;   // filas 9..32
    private const COLUMNAS_PRODUCTO = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T'];

    /** Constantes de conversión tal como vienen en la plantilla (columnas G y P). */
    private const FACTOR_GRAMOS_A_ONZAS = 0.035274;
    private const FACTOR_KG_A_LB = 2.2046;

    /** Genera el .xlsx en un archivo temporal y devuelve su ruta. */
    public function generar(Exportacion $exportacion): string
    {
        $exportacion->loadMissing('items');
        if ($exportacion->items->isEmpty()) {
            throw new RuntimeException('La exportación no tiene productos.');
        }

        $spreadsheet = IOFactory::load($this->rutaPlantilla());

        // Conservar SOLO la hoja "Lista": las demás (p. ej. "Factura") quedan fuera
        // del módulo y además traen referencias rotas que no deben viajar en el archivo.
        $lista = $this->hojaLista($spreadsheet);
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($sheet !== $lista) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($sheet));
            }
        }
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($lista));

        $this->llenarEncabezado($lista, $exportacion);
        $this->llenarProductos($lista, $exportacion);

        $ruta = tempnam(sys_get_temp_dir(), 'lista_empaque_').'.xlsx';
        (new Xlsx($spreadsheet))->save($ruta);

        return $ruta;
    }

    public function nombreArchivo(Exportacion $exportacion): string
    {
        return sprintf('lista-empaque-%d-%s.xlsx', $exportacion->id, $exportacion->fecha->format('Y-m-d'));
    }

    public function rutaPlantilla(): string
    {
        $ruta = storage_path('app/'.config('exportaciones.plantilla'));
        if (! is_file($ruta)) {
            throw new RuntimeException('No se encontró la plantilla de lista de empaque: '.$ruta);
        }

        return $ruta;
    }

    /** La hoja de la lista se llama "Lista " (con espacio final): buscar por nombre saneado. */
    public function hojaLista(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (strcasecmp(trim($sheet->getTitle()), 'Lista') === 0) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheet(0);
    }

    private function llenarEncabezado(Worksheet $hoja, Exportacion $e): void
    {
        $hoja->setCellValue('C2', $e->exportador_nombre);
        $hoja->setCellValue('C3', (string) $e->exportador_direccion);
        $hoja->setCellValue('L2', $e->cliente_nombre);
        $hoja->setCellValue('L3', (string) $e->cliente_direccion);
        // Factura: texto libre opcional (la factura comercial NO se implementa todavía).
        $hoja->setCellValueExplicit('C4', (string) $e->factura, DataType::TYPE_STRING);
        // Fecha como fecha real de Excel; la celda ya trae el formato m/d/yyyy de la plantilla.
        $hoja->setCellValue('C5', ExcelDate::PHPToExcel($e->fecha->startOfDay()));
        // FDA como texto para no perder ceros iniciales.
        $hoja->setCellValueExplicit('E5', (string) $e->fda_reg_number, DataType::TYPE_STRING);
    }

    private function llenarProductos(Worksheet $hoja, Exportacion $e): void
    {
        $n = $e->items->count();
        $primera = self::FILA_PRIMER_PRODUCTO;                          // 9
        $ultimaPlantilla = $primera + self::FILAS_PRODUCTO_PLANTILLA - 1; // 32

        if ($n > self::FILAS_PRODUCTO_PLANTILLA) {
            // Insertar DENTRO del rango de productos (antes de la última fila) para
            // que los =SUM(...) de la fila de totales se expandan solos.
            $extra = $n - self::FILAS_PRODUCTO_PLANTILLA;
            $hoja->insertNewRowBefore($ultimaPlantilla, $extra);
            // Copiar el estilo de la fila base (9) a las filas insertadas.
            foreach (range($ultimaPlantilla, $ultimaPlantilla + $extra - 1) as $fila) {
                foreach (self::COLUMNAS_PRODUCTO as $col) {
                    $hoja->duplicateStyle($hoja->getStyle($col.$primera), $col.$fila);
                }
            }
        } elseif ($n < self::FILAS_PRODUCTO_PLANTILLA) {
            // Quitar las filas sobrantes: los totales suben y los =SUM se reajustan.
            $hoja->removeRow($primera + $n, self::FILAS_PRODUCTO_PLANTILLA - $n);
        }

        foreach ($e->items->values() as $i => $item) {
            $this->llenarFila($hoja, $primera + $i, $item);
        }
    }

    /**
     * Una fila de producto con el MISMO esquema de la plantilla: valores del snapshot
     * en las columnas base y fórmulas en los totales por fila, para que el archivo
     * siga siendo editable en Excel como el original.
     */
    private function llenarFila(Worksheet $hoja, int $r, ExportacionItem $item): void
    {
        $hoja->setCellValue('B'.$r, $item->cantidad_cajas);
        $hoja->setCellValue('C'.$r, $item->descripcionCombinada());
        $hoja->setCellValue('D'.$r, (string) $item->unidad);
        $hoja->setCellValue('E'.$r, $item->unidades_por_caja);
        $hoja->setCellValue('F'.$r, (float) $item->gramos_por_unidad);
        $hoja->setCellValue('G'.$r, self::FACTOR_GRAMOS_A_ONZAS);
        // Onzas del snapshot (la plantilla usa =F*G; respetamos el dato guardado).
        $hoja->setCellValue('H'.$r, (float) $item->onzas_por_unidad);
        $hoja->setCellValue('I'.$r, "=B{$r}*E{$r}");
        $hoja->setCellValue('J'.$r, (float) $item->precio_caja);
        $hoja->setCellValue('K'.$r, "=B{$r}*J{$r}");
        $hoja->setCellValue('L'.$r, (float) $item->peso_neto_caja_kg);
        // Bruto del snapshot (la plantilla usa =L+1; respetamos el dato guardado).
        $hoja->setCellValue('M'.$r, (float) $item->peso_bruto_caja_kg);
        $hoja->setCellValue('N'.$r, "=B{$r}*L{$r}");
        $hoja->setCellValue('O'.$r, "=B{$r}*M{$r}");
        $hoja->setCellValue('P'.$r, self::FACTOR_KG_A_LB);
        $hoja->setCellValue('Q'.$r, (float) $item->peso_neto_caja_lb);
        $hoja->setCellValue('R'.$r, (float) $item->peso_bruto_caja_lb);
        $hoja->setCellValue('S'.$r, "=B{$r}*Q{$r}");
        $hoja->setCellValue('T'.$r, "=B{$r}*R{$r}");
    }
}
