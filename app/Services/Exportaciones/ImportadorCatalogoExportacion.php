<?php

namespace App\Services\Exportaciones;

use App\Http\Requests\Exportaciones\PresentacionExportacionRequest;
use App\Models\ExportacionProducto;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Importa el catálogo inicial de productos de exportación desde la hoja "Lista"
 * del Excel de lista de empaque (la plantilla u otro archivo con el mismo layout).
 *
 * Lee las filas de productos (desde la fila 9 hasta la fila de totales). De los
 * pesos toma solo gramos, neto y bruto en kilos; onzas y libras las calcula
 * {@see CatalogoExportacion::pesos()}, como el formulario, y no las lee del Excel.
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

        // Un Excel dañado que igual se llama .xlsx pasa el control del tipo de archivo y
        // al leerlo revienta con un aviso de PHP: sin esto, el usuario veía una página de error.
        try {
            $spreadsheet = IOFactory::load($ruta);
            $hoja = app(ListaEmpaqueExcelService::class)->hojaLista($spreadsheet);
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el Excel: está dañado o no es la lista de empaque. Abrilo en Excel, guardalo de nuevo y volvé a subirlo. No se importó nada.');
        }

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

        // Onzas y libras se calculan igual que en el formulario; las columnas H, Q y R
        // del Excel no se leen. Sin peso bruto, la caja pesa neto + 1 kg.
        $pesos = CatalogoExportacion::pesos($num('F'), $num('L'), $num('M') > 0 ? $num('M') : null);

        $datos = [
            'nombre_es' => $nombreEs,
            'nombre_en' => $nombreEn,
            'unidad' => trim((string) $hoja->getCell('D'.$fila)->getValue()) ?: null,
            'unidades_por_caja' => (int) $hoja->getCell('E'.$fila)->getCalculatedValue(),
            'gramos_por_unidad' => $num('F'),
            'onzas_por_unidad' => $pesos['onzas'],
            'precio_caja' => $num('J'),
            'peso_neto_caja_kg' => $num('L'),
            'peso_bruto_caja_kg' => $pesos['bruto_kg'],
            'peso_neto_caja_lb' => $pesos['neto_lb'],
            'peso_bruto_caja_lb' => $pesos['bruto_lb'],
            'activo' => true,
        ];

        $this->exigirDatosPosibles($datos);

        ExportacionProducto::create($datos);

        $resumen['creados']++;
    }

    /**
     * Las mismas reglas que el formulario de una presentación
     * ({@see PresentacionExportacionRequest}): una caja sin empaque, sin unidades, sin
     * peso o con precio negativo terminaría en una lista de empaque y en su factura.
     * La fila se informa como error y no se crea.
     */
    private function exigirDatosPosibles(array $datos): void
    {
        $problemas = array_filter([
            $datos['unidad'] === null ? 'falta el empaque (columna D)' : null,
            $datos['unidades_por_caja'] < 1 ? 'las unidades por caja tienen que ser al menos 1' : null,
            $datos['gramos_por_unidad'] <= 0 ? 'los gramos por unidad tienen que ser más que 0' : null,
            $datos['peso_neto_caja_kg'] <= 0 ? 'el peso neto por caja tiene que ser más que 0' : null,
            $datos['peso_bruto_caja_kg'] < $datos['peso_neto_caja_kg'] ? 'el peso bruto por caja no puede ser menor que el neto' : null,
            $datos['precio_caja'] < 0 ? 'el precio por caja no puede ser negativo' : null,
        ]);

        if ($problemas !== []) {
            throw new RuntimeException("«{$datos['nombre_es']}» no se importó: ".implode(', ', $problemas).'.');
        }
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
