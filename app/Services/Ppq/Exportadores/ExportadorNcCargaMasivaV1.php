<?php

namespace App\Services\Ppq\Exportadores;

use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\NcExportacion;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Formato de CARGA MASIVA de notas de crédito del portal del cliente: ocho columnas en
 * una sola fila de encabezados. Réplica de «FORMATO DE CARGA MASIVA NOTA-CREDITO.xlsx».
 *
 * Es un formato DISTINTO, no una versión del anterior: el de 17 columnas
 * ({@see ExportadorNcAlbaranV1}) describe la nota entera —sus valores fiscales incluidos—
 * y viaja adjunto a un correo; este solo IDENTIFICA el albarán y le cuelga el código de
 * generación de la nota, y se sube al portal. Por eso conviven: los lotes viejos siguen
 * bajándose con su formato original y los nuevos salen con este.
 *
 * Cuatro cosas que no se ven mirando el archivo:
 *
 *  1. **Los encabezados van copiados al carácter**, con sus tildes, sus minúsculas y el
 *     DOBLE espacio de «MES  (en numero)». Son la firma que el portal reconoce; una
 *     corrección ortográfica bienintencionada es un archivo rechazado.
 *
 *  2. **Año y mes salen de la FECHA DEL ALBARÁN**, no de la fecha de emisión de la nota.
 *     Una nota de octubre puede acreditar un albarán de septiembre, y el portal está
 *     clasificando el albarán. Si el albarán no tiene fecha registrada, la fila no se
 *     escribe con un año supuesto: la nota se rechaza antes, en {@see faltantes()}.
 *
 *  3. **El código de generación es el de la NOTA**, según su propio encabezado: «COD DE
 *     GENERACION DE LA NC ASOCIADA». Los otros cinco datos son del albarán.
 *
 *  4. **Ad valorem y específicos quedan VACÍOS.** La plantilla los marca «si aplica» y el
 *     giro no los usa; escribir 0.00 sería declarar un impuesto calculado en cero, que no
 *     es lo mismo que no tenerlo. Acá no se calcula ningún valor fiscal.
 */
class ExportadorNcCargaMasivaV1 implements ExportadorNc
{
    /**
     * Los ocho encabezados de la fila 1, EXACTAMENTE como vienen en la plantilla y en ese
     * orden. El doble espacio de «MES  (en numero)» es intencional: está en el archivo del
     * cliente.
     */
    private const COLUMNAS = [
        'CODIGO SALA O CD',
        '# ALBARAN',
        'AÑO (ultimos 2 digitos)',
        'MES  (en numero)',
        'TIPO ALBARAN',
        'COD DE GENERACION DE LA NC ASOCIADA',
        'Advaloren (si aplica)',
        'Especificos (si aplica)',
    ];

    /**
     * Columnas que la plantilla trae formateadas como TEXTO (`@`). Sin eso, `0033` se
     * guarda como 33 y el portal no encuentra la sala.
     */
    private const COLUMNAS_TEXTO = ['A', 'B', 'E', 'F'];

    /** La plantilla trae una sola fila de encabezados: los datos arrancan en la 2. */
    private const PRIMERA_FILA = 2;

    /** Nombre de la hoja en la plantilla del cliente. Se conserva tal cual. */
    private const HOJA = 'Hoja2';

    public static function slug(): string
    {
        return 'carga_masiva_nc_v1';
    }

    public static function nombre(): string
    {
        return 'Carga masiva de notas de crédito (portal de Calleja)';
    }

    public static function entrega(): string
    {
        return 'Este archivo se carga en el portal de Calleja. Descargarlo no significa que Calleja '
            .'lo haya recibido ni registrado: la carga la hace una persona en el portal y el '
            .'resultado se confirma ahí.';
    }

    /**
     * Los seis datos que el portal necesita para identificar la fila. Cinco salen del
     * albarán y uno de la nota; ninguno se suple con un valor por defecto.
     *
     * @return array<int, string>
     */
    public function faltantes(Dte $nc, ClientePerfilDocumento $perfil): array
    {
        $albaran = $nc->albaran;

        if ($albaran === null) {
            return ['el albarán asociado (de ahí salen sala, número, año, mes y tipo)'];
        }

        $faltan = [];

        if (blank($albaran->sala_codigo)) {
            $faltan[] = 'el código de sala o CD del albarán';
        }
        if (blank($albaran->numero)) {
            $faltan[] = 'el número del albarán';
        }
        if ($albaran->fecha === null) {
            $faltan[] = 'la fecha del albarán (de ahí salen el año y el mes)';
        }
        if (blank($albaran->tipo_codigo)) {
            $faltan[] = 'el tipo de albarán';
        }
        if (blank($nc->codigo_generacion)) {
            $faltan[] = 'el código de generación de la nota de crédito';
        }

        return $faltan;
    }

    public function generar(NcExportacion $lote, ClientePerfilDocumento $perfil): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(self::HOJA);

        $this->encabezado($hoja);

        $fila = self::PRIMERA_FILA;
        foreach ($lote->notas() as $nc) {
            $this->fila($hoja, $fila, $nc);
            $fila++;
        }

        $this->formato($hoja, $fila - 1);

        $ruta = tempnam(sys_get_temp_dir(), 'nc_carga_masiva_');
        if ($ruta === false) {
            $libro->disconnectWorksheets();

            throw new RuntimeException('No se pudo preparar el archivo de notas de crédito.');
        }

        try {
            (new Xlsx($libro))->save($ruta);
        } catch (Throwable $e) {
            @unlink($ruta);

            throw $e;
        } finally {
            $libro->disconnectWorksheets();
        }

        return $ruta;
    }

    private function encabezado(Worksheet $hoja): void
    {
        foreach (self::COLUMNAS as $i => $titulo) {
            // Explícito como texto: los encabezados se comparan carácter a carácter y no
            // deben pasar por ninguna conversión.
            $hoja->setCellValueExplicit([$i + 1, 1], $titulo, DataType::TYPE_STRING);
        }

        $hoja->getStyle('A1:H1')->getFont()->setBold(true);
        $hoja->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    }

    private function fila(Worksheet $hoja, int $fila, Dte $nc): void
    {
        /** @var DteAlbaran|null $albaran */
        $albaran = $nc->albaran;

        // A–B · identidad del albarán. Texto, para no perder los ceros de `0033`.
        $this->texto($hoja, 'A', $fila, (string) $albaran?->sala_codigo);
        $this->texto($hoja, 'B', $fila, (string) $albaran?->numero);

        // C–D · año y mes DEL ALBARÁN. La plantilla deja estas dos columnas en formato
        // general (numérico), a diferencia de las otras cuatro; se respeta.
        if ($albaran?->fecha !== null) {
            $hoja->setCellValue([3, $fila], (int) $albaran->fecha->format('y'));
            $hoja->setCellValue([4, $fila], (int) $albaran->fecha->format('n'));
        }

        // E · tipo tal como quedó registrado (AC02, AC04…). La plantilla no trae filas de
        // muestra, así que no hay evidencia de que el portal lo quiera en minúsculas como
        // el otro formato: se manda el código canónico, sin transformarlo.
        $this->texto($hoja, 'E', $fila, (string) $albaran?->tipo_codigo);

        // F · código de generación DE LA NOTA (así lo pide el encabezado).
        $this->texto($hoja, 'F', $fila, (string) $nc->codigo_generacion);

        // G–H · ad valorem y específicos: vacíos a propósito. Ver la nota 4 de la clase.
    }

    private function texto(Worksheet $hoja, string $col, int $fila, string $valor): void
    {
        $hoja->setCellValueExplicit($col.$fila, $valor, DataType::TYPE_STRING);
    }

    private function formato(Worksheet $hoja, int $ultimaFila): void
    {
        if ($ultimaFila >= self::PRIMERA_FILA) {
            foreach (self::COLUMNAS_TEXTO as $col) {
                $hoja->getStyle($col.self::PRIMERA_FILA.':'.$col.$ultimaFila)
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }

        foreach (range('A', 'H') as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
