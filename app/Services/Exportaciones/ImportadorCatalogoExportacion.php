<?php

namespace App\Services\Exportaciones;

use App\Models\ExportacionProducto;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Importa el catálogo inicial de productos de exportación desde la hoja "Lista"
 * del Excel de lista de empaque (la plantilla u otro archivo con el mismo layout).
 *
 * Lee las filas de productos (desde la fila 9 hasta la fila de totales) tomando
 * los VALORES CALCULADOS de las columnas con fórmula (onzas, bruto, libras).
 * No re-importa productos existentes (mismo nombre_es): los omite.
 */
class ImportadorCatalogoExportacion
{
    /**
     * @return array{creados: int, omitidos: int, errores: list<string>}
     */
    public function importar(?string $rutaXlsx = null): array
    {
        $ruta = $rutaXlsx ?? app(ListaEmpaqueExcelService::class)->rutaPlantilla();
        if (! is_file($ruta)) {
            throw new RuntimeException('No se encontró el archivo a importar: '.$ruta);
        }

        $spreadsheet = IOFactory::load($ruta);
        $hoja = app(ListaEmpaqueExcelService::class)->hojaLista($spreadsheet);

        $resumen = ['creados' => 0, 'omitidos' => 0, 'errores' => []];

        $filaInicio = $this->filaPrimerProducto($hoja);
        $ultimaFila = $hoja->getHighestRow();

        for ($fila = $filaInicio; $fila <= $ultimaFila; $fila++) {
            // Fin de la tabla: llegó a los totales (=SUM) o a una descripción vacía.
            $valorB = $hoja->getCell('B'.$fila)->getValue();
            if (is_string($valorB) && str_starts_with(strtoupper($valorB), '=SUM')) {
                break;
            }
            $descripcion = trim((string) $hoja->getCell('C'.$fila)->getValue());
            if ($descripcion === '') {
                break;
            }

            try {
                $this->importarFila($hoja, $fila, $descripcion, $resumen);
            } catch (Throwable $e) {
                $resumen['errores'][] = "Fila {$fila}: {$e->getMessage()}";
            }
        }

        return $resumen;
    }

    private function importarFila(Worksheet $hoja, int $fila, string $descripcion, array &$resumen): void
    {
        [$nombreEs, $nombreEn] = $this->separarDescripcion($descripcion);

        if (ExportacionProducto::where('nombre_es', $nombreEs)->exists()) {
            $resumen['omitidos']++;

            return;
        }

        $num = fn (string $col): float => round((float) $hoja->getCell($col.$fila)->getCalculatedValue(), 2);

        ExportacionProducto::create([
            'nombre_es' => $nombreEs,
            'nombre_en' => $nombreEn,
            'unidad' => trim((string) $hoja->getCell('D'.$fila)->getValue()) ?: null,
            'unidades_por_caja' => (int) $hoja->getCell('E'.$fila)->getCalculatedValue(),
            'gramos_por_unidad' => $num('F'),
            'onzas_por_unidad' => $num('H'),
            'precio_caja' => $num('J'),
            'peso_neto_caja_kg' => $num('L'),
            'peso_bruto_caja_kg' => $num('M'),
            'peso_neto_caja_lb' => $num('Q'),
            'peso_bruto_caja_lb' => $num('R'),
            'activo' => true,
        ]);

        $resumen['creados']++;
    }

    /**
     * La descripción de la plantilla combina "español \ english" en una celda.
     * Si no trae separador, todo queda como nombre_es y nombre_en vacío.
     *
     * @return array{0: string, 1: string}
     */
    public function separarDescripcion(string $descripcion): array
    {
        $partes = array_map('trim', explode('\\', $descripcion, 2));

        return [$partes[0], $partes[1] ?? ''];
    }

    /** Busca la fila de encabezados ("Descripción" en C) y devuelve la siguiente. */
    private function filaPrimerProducto(Worksheet $hoja): int
    {
        for ($fila = 1; $fila <= 30; $fila++) {
            $c = mb_strtolower(trim((string) $hoja->getCell('C'.$fila)->getValue()));
            if (str_contains($c, 'descripci')) {
                return $fila + 1;
            }
        }

        // Layout estándar de la plantilla (encabezados en la fila 8).
        return 9;
    }
}
