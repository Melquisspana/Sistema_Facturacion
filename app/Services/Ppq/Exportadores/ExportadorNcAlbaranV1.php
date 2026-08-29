<?php

namespace App\Services\Ppq\Exportadores;

use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\NcExportacion;
use App\Support\Dinero;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Formato de notas de crédito con datos de albarán: 17 columnas en tres bandas
 * (información de la NC · información del albarán · valores de la NC), más dos columnas
 * opcionales de impuestos específicos. Réplica de FORMATO-NOTA-CREDITO.xlsx.
 *
 * Tres decisiones que no son obvias al mirar el archivo del cliente:
 *
 *  1. **GRAVADO va NETO.** El formato no tiene columna de descuento, así que el descuento
 *     se absorbe dentro de GRAVADO: la fila de muestra trae 0.85 para una nota cuyo bruto
 *     era 0.90. Nuestro `total_gravado` guarda el BRUTO porque así lo exige la estructura
 *     v3 del MH, de modo que acá se escribe `total_gravado − descuento_gravado`.
 *
 *  2. **TOTAL se escribe como VALOR.** En el archivo del cliente la columna O es la
 *     fórmula `=I`, lo que fuerza a que la nota valga lo mismo que el albarán. Eso sirve
 *     como control visual pero MIENTE cuando no coinciden. Acá se escribe el total fiscal
 *     real de la nota; si difiere del albarán, la diferencia se ve, que es justo lo que
 *     debe pasar.
 *
 *  3. **Todo va en positivo.** El albarán imprime el abono en negativo; las filas de
 *     muestra del formato están en positivo. Se respeta el formato.
 *
 * Los valores salen SIEMPRE de la cabecera del DTE, nunca de sumar líneas: en la NC v3 las
 * líneas guardan venta e IVA BRUTOS (el descuento global vive solo en el resumen), así que
 * agregarlas daría un IVA distinto al declarado ante Hacienda.
 */
class ExportadorNcAlbaranV1 implements ExportadorNc
{
    /** Bandas fusionadas de la fila 1. */
    private const BANDAS = [
        'A1:E1' => 'INFORMACION DE NOTA DE CREDITO',
        'F1:J1' => 'INFORMACION DEL ALBARAN',
        'K1:O1' => 'VALORES DE NOTA DE CREDITO',
        'P1:Q1' => '(Colocar si aplica)',
    ];

    /** Encabezados de la fila 2, en el orden exacto del formato del cliente. */
    private const COLUMNAS = [
        'CODIGO PROVEEDOR',
        'CODIGO DE GENERACIÓN',
        'NÚMERO DE CONTROL',
        'SELLO DE RECEPCIÓN',
        'FECHA DE EMISION DE NOTA DE CREDITO',
        'CODIGO DE SALA DE VENTA O CD',
        'TIPO DE ALBARAN',
        'NUMERO DE ALBARAN',
        'TOTAL DE ALBARAN',
        'FECHA EMISIÓN DE ALBARAN',
        'EXENTO',
        'GRAVADO',
        'IVA',
        'RETENCION',
        'TOTAL',
        'ESPECIFICOS',
        'ADVALOREM',
    ];

    /**
     * Columnas que van como TEXTO explícito. Sin esto, `001065` se guarda como 1065 y
     * `0033` como 33: Excel se come los ceros iniciales de cualquier cosa que parezca un
     * número, y esos ceros son parte del código.
     */
    private const COLUMNAS_TEXTO = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J'];

    /** Columnas de dinero, a dos decimales. */
    private const COLUMNAS_MONTO = ['I', 'K', 'L', 'M', 'N', 'O'];

    private const PRIMERA_FILA = 3;

    public static function slug(): string
    {
        return 'albaran_nc_v1';
    }

    public function generar(NcExportacion $lote, ClientePerfilDocumento $perfil): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Hoja1');

        $this->encabezado($hoja);

        $fila = self::PRIMERA_FILA;
        foreach ($lote->notas() as $nc) {
            $this->fila($hoja, $fila, $nc, $perfil);
            $fila++;
        }

        $this->formato($hoja, $fila - 1);

        $ruta = tempnam(sys_get_temp_dir(), 'nc_albaran_').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    private function encabezado(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja): void
    {
        foreach (self::BANDAS as $rango => $titulo) {
            $hoja->mergeCells($rango);
            $hoja->setCellValue(explode(':', $rango)[0], $titulo);
        }

        foreach (self::COLUMNAS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 2], $titulo);
        }

        $hoja->getStyle('A1:Q2')->getFont()->setBold(true);
        $hoja->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $hoja->getStyle('A2:Q2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        $hoja->getStyle('A1:Q2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
    }

    private function fila(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja,
        int $fila,
        Dte $nc,
        ClientePerfilDocumento $perfil,
    ): void {
        $albaran = $nc->albaran;

        // A–E · identidad fiscal de la nota.
        $this->texto($hoja, 'A', $fila, (string) $perfil->codigo_proveedor);
        $this->texto($hoja, 'B', $fila, (string) $nc->codigo_generacion);
        $this->texto($hoja, 'C', $fila, (string) $nc->numero_control);
        $this->texto($hoja, 'D', $fila, (string) $nc->sello_recepcion);
        $this->texto($hoja, 'E', $fila, (string) $nc->fecha_emision?->format('d/m/Y'));

        // F–J · el albarán que originó la nota.
        $this->texto($hoja, 'F', $fila, (string) $albaran?->sala_codigo);
        // El cliente escribe el tipo en minúsculas en su propio formato ("ac02"); el
        // código canónico se guarda en mayúsculas y se adapta acá, que es donde vive el
        // convenio de ESTE formato.
        $this->texto($hoja, 'G', $fila, mb_strtolower((string) $albaran?->tipo_codigo));
        $this->texto($hoja, 'H', $fila, (string) $albaran?->numero);
        $this->monto($hoja, 'I', $fila, $albaran?->total);
        $this->texto($hoja, 'J', $fila, (string) $albaran?->fecha?->format('d/m/Y'));

        // K–O · valores de la nota, todos de CABECERA.
        $this->monto($hoja, 'K', $fila, $nc->total_exento);
        $this->monto($hoja, 'L', $fila, $this->gravadoNeto($nc));
        $this->monto($hoja, 'M', $fila, $nc->iva);
        $this->monto($hoja, 'N', $fila, $nc->iva_retenido);
        $this->monto($hoja, 'O', $fila, $nc->total_pagar);

        // P–Q · específicos y ad valorem: el giro no los usa, y el propio formato los
        // marca como "colocar si aplica". Se dejan vacíos, no en cero, para no declarar
        // un impuesto que no existe en el documento.
    }

    /**
     * GRAVADO del formato = base gravada NETA de descuento. Ver la nota 1 de la clase.
     */
    private function gravadoNeto(Dte $nc): string
    {
        return Dinero::redondear(
            Dinero::restar((string) $nc->total_gravado, (string) $nc->descuento_gravado),
            2
        );
    }

    private function texto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, string $col, int $fila, string $valor): void
    {
        $hoja->setCellValueExplicit($col.$fila, $valor, DataType::TYPE_STRING);
    }

    private function monto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, string $col, int $fila, string|float|null $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $hoja->setCellValue($col.$fila, round(abs((float) $valor), 2));
    }

    private function formato(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, int $ultimaFila): void
    {
        if ($ultimaFila >= self::PRIMERA_FILA) {
            foreach (self::COLUMNAS_TEXTO as $col) {
                $hoja->getStyle($col.self::PRIMERA_FILA.':'.$col.$ultimaFila)
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            foreach (self::COLUMNAS_MONTO as $col) {
                $hoja->getStyle($col.self::PRIMERA_FILA.':'.$col.$ultimaFila)
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            }
        }

        foreach (range('A', 'Q') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
