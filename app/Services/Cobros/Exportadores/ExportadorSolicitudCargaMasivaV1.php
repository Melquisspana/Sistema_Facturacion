<?php

namespace App\Services\Cobros\Exportadores;

use App\Enums\EstadoDte;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudItem;
use App\Support\NumeroAlbaran;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Formato de CARGA MASIVA de solicitudes (los «quedan») del portal del cliente: cinco
 * columnas en una sola fila de encabezados. Se genera A PARTIR de la plantilla original
 * «FORMATO DE CARGA MASIVA QUEDAN.xlsx», incluida en resources/templates/calleja.
 *
 * ═══════════════ Es un archivo de IDENTIFICACIÓN, no de importes ═══════════════
 *
 * La solicitud histórica (`000123202609040951.xlsx`) llevaba la orden de compra, los
 * montos, el código de generación, el sello y la sala por nombre: era una carta con todo
 * el detalle. Esta no. Las cinco columnas identifican ENTREGAS —sala, número, año, mes y
 * tipo del albarán— y nada más. El portal ya sabe qué facturó cada entrega.
 *
 * Dos consecuencias que conviene tener presentes:
 *
 *  1. **Los importes no viajan**, así que no hay nada que cuadrar dentro del archivo. El
 *     control del importe vive en el seguimiento, no acá.
 *  2. **Las notas de crédito no van.** En el formato antiguo iban como filas negativas al
 *     final; este archivo no tiene columna donde ponerlas y el circuito de NC ya tiene su
 *     propia carga masiva. Arrastrarlas acá sería descontarlas dos veces.
 *
 * ═══════════════════════ Los cinco datos salen del ALBARÁN ═══════════════════════
 *
 * Los cinco, incluidos el año y el mes: el portal está clasificando la ENTREGA, no la
 * factura. Un CCF de septiembre puede amparar un albarán de agosto, y meter ahí el mes de
 * emisión de la factura manda la entrega al período equivocado.
 *
 * Y sin albarán no hay fila: {@see faltantes()} lo dice antes de armar nada, en vez de
 * escribir una celda vacía o un dato supuesto.
 *
 * Los encabezados van copiados al carácter, con el DOBLE espacio de «MES  (en numero)».
 * Son la firma que el portal reconoce.
 */
class ExportadorSolicitudCargaMasivaV1 implements ExportadorSolicitud
{
    /** Los cinco encabezados de la fila 1, EXACTAMENTE como vienen en la plantilla. */
    private const COLUMNAS = [
        'CODIGO DE SALA O CD',
        '# ALBARAN',
        'AÑO (ultimos 2 digitos)',
        'MES  (en numero)',
        'TIPO ALBARAN',
    ];

    /**
     * Columnas que van como TEXTO: la sala conserva su cero inicial (`0017`) y el número y
     * el tipo son códigos, no cantidades. La plantilla no fuerza formato en ninguna
     * columna, así que acá manda la fidelidad del dato: `0017` convertido a 17 es una sala
     * que el portal no encuentra.
     */
    private const COLUMNAS_TEXTO = ['A', 'B', 'E'];

    private const PRIMERA_FILA = 2;

    /** Nombre de la hoja en la plantilla del cliente. Se conserva tal cual. */
    private const HOJA = 'Hoja3';

    public static function slug(): string
    {
        return 'solicitud_carga_masiva_v1';
    }

    public static function nombre(): string
    {
        return 'Carga masiva de solicitudes / quedan (portal de Calleja)';
    }

    public static function entrega(): string
    {
        return 'Este archivo se carga en el portal de Calleja. Descargarlo no es presentarlo: '
            .'la carga la hace una persona en el portal, y hasta que alguien lo declare acá la '
            .'solicitud sigue figurando como no presentada.';
    }

    /**
     * Los cinco datos del albarán. Ninguno se suple con un valor por defecto.
     *
     * @return array<int, string>
     */
    public function faltantes(CobroDocumento $documento): array
    {
        if ($documento->esNc()) {
            return ['este formato no lleva notas de crédito: se presentan por su propio formato'];
        }

        return $documento->faltantesParaPresentar();
    }

    /**
     * @param  string|null  $plantilla  ruta de la plantilla; null = la incluida en el
     *                                  proyecto. Solo las pruebas la cambian.
     */
    public function __construct(private readonly ?string $plantilla = null) {}

    /**
     * La plantilla ORIGINAL del cliente, copiada al proyecto sin modificarla. Se lee de
     * acá y nunca de una carpeta del usuario: el archivo que se presenta no puede depender
     * de qué haya en el Escritorio de quien lo genera.
     */
    public function rutaPlantilla(): string
    {
        return $this->plantilla ?? resource_path('templates/calleja/FORMATO DE CARGA MASIVA QUEDAN.xlsx');
    }

    /**
     * Parte de la PLANTILLA y solo le agrega filas: encabezados, nombre de hoja, anchos y
     * estilos quedan como los dejó el cliente. Si la plantilla falta, está dañada o ya no
     * trae la hoja y los encabezados esperados, se detiene: un formato improvisado es un
     * archivo que el portal puede rechazar o, peor, leer mal.
     *
     * @throws RuntimeException
     */
    public function generar(CobroSolicitud $solicitud): string
    {
        $libro = $this->abrirPlantilla();
        $hoja = $libro->getSheetByName(self::HOJA);
        $libro->setActiveSheetIndex($libro->getIndex($hoja));

        $fila = self::PRIMERA_FILA;
        foreach ($solicitud->items as $item) {
            $this->fila($hoja, $fila, $item);
            $fila++;
        }

        // Después de los CCF, sus NC con su albarán de crédito (AC02/AC04): en el portal la
        // solicitud de quedan también las lleva. Regla del usuario (25/09/2026).
        foreach (self::filasDeNotas($solicitud) as $datos) {
            [$sala, $numero, $anio, $mes, $tipo] = self::celdas($datos);
            $this->texto($hoja, 'A', $fila, $sala);
            $this->texto($hoja, 'B', $fila, $numero);
            $hoja->setCellValue([3, $fila], $anio);
            $hoja->setCellValue([4, $fila], $mes);
            $this->texto($hoja, 'E', $fila, $tipo);
            $fila++;
        }

        $this->formato($hoja, $fila - 1);

        // El archivo de tempnam() se usa tal cual: agregarle «.xlsx» dejaba el original
        // vacío huérfano. Quien lo recibe es el servicio, que lee los bytes y lo borra.
        $ruta = tempnam(sys_get_temp_dir(), 'cobro_solicitud_');

        if ($ruta === false) {
            $libro->disconnectWorksheets();

            throw new RuntimeException('No se pudo crear el archivo temporal de la solicitud.');
        }

        try {
            (new Xlsx($libro))->save($ruta);
        } catch (Throwable $e) {
            @unlink($ruta);

            throw new RuntimeException('No se pudo escribir el archivo de la solicitud: '.$e->getMessage(), 0, $e);
        } finally {
            $libro->disconnectWorksheets();
        }

        return $ruta;
    }

    /** @throws RuntimeException */
    private function abrirPlantilla(): Spreadsheet
    {
        $ruta = $this->rutaPlantilla();

        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException("Falta la plantilla de carga masiva de quedan ({$ruta}). No se genera un formato sustituto.");
        }

        try {
            $libro = IOFactory::createReader('Xlsx')->load($ruta);
        } catch (Throwable $e) {
            throw new RuntimeException('La plantilla de carga masiva de quedan no se pudo leer: '.$e->getMessage(), 0, $e);
        }

        $hoja = $libro->getSheetByName(self::HOJA);
        $encabezados = $hoja === null ? [] : array_map(
            fn (int $col) => (string) $hoja->getCell([$col, 1])->getValue(),
            range(1, count(self::COLUMNAS)),
        );

        if ($encabezados !== self::COLUMNAS) {
            $libro->disconnectWorksheets();

            throw new RuntimeException('La plantilla de carga masiva de quedan no trae la hoja «'.self::HOJA
                .'» con los encabezados esperados. No se genera un formato sustituto.');
        }

        return $libro;
    }

    /**
     * La fila se escribe desde el SNAPSHOT del renglón, no desde el albarán actual: lo que
     * se presentó tiene que seguir siendo lo que se presentó aunque el albarán se corrija
     * después ({@see CobroSolicitudItem}).
     */
    private function fila(Worksheet $hoja, int $fila, CobroSolicitudItem $item): void
    {
        [$sala, $numero, $anio, $mes, $tipo] = self::celdas($item->only([
            'sala_codigo', 'albaran_numero', 'albaran_anio', 'albaran_mes', 'albaran_tipo',
        ]));

        $this->texto($hoja, 'A', $fila, $sala);
        $this->texto($hoja, 'B', $fila, $numero);

        if ($anio !== null) {
            $hoja->setCellValue([3, $fila], $anio);
        }
        if ($mes !== null) {
            $hoja->setCellValue([4, $fila], $mes);
        }

        $this->texto($hoja, 'E', $fila, $tipo);
    }

    /**
     * Filas de las NC que respaldaron a los CCF de la solicitud, desde el albarán de
     * crédito propio de cada NC. Las invalidadas o sin albarán legible no van.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function filasDeNotas(CobroSolicitud $solicitud): array
    {
        $filas = [];
        foreach ($solicitud->notas()->with('dte.albaran')->orderBy('id')->get() as $nota) {
            $dte = $nota->dte;
            $albaran = $dte?->albaran;
            $partes = NumeroAlbaran::desde($albaran?->numero_canonico);
            if ($dte === null || $dte->estado === EstadoDte::Invalidado || $partes === null || $albaran->fecha === null) {
                continue;
            }
            $fecha = Carbon::parse($albaran->fecha);
            $filas[] = [
                'sala_codigo' => $partes->sala,
                'albaran_numero' => $partes->numero,
                'albaran_anio' => (int) $fecha->format('y'),
                'albaran_mes' => (int) $fecha->format('n'),
                'albaran_tipo' => $partes->tipo,
            ];
        }

        return $filas;
    }

    /** Los cinco encabezados de la plantilla, para mostrarlos tal cual (vista previa). */
    public static function columnas(): array
    {
        return self::COLUMNAS;
    }

    /**
     * Los cinco valores de las celdas A–E tal como se escriben, a partir de los datos de
     * fila ({@see datosDeFila()}) o del renglón congelado. Año y mes vacíos quedan null:
     * la celda no se escribe.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: string, 1: string, 2: int|null, 3: int|null, 4: string}
     */
    public static function celdas(array $datos): array
    {
        return [
            (string) ($datos['sala_codigo'] ?? ''),
            (string) ($datos['albaran_numero'] ?? ''),
            isset($datos['albaran_anio']) ? (int) $datos['albaran_anio'] : null,
            isset($datos['albaran_mes']) ? (int) $datos['albaran_mes'] : null,
            (string) ($datos['albaran_tipo'] ?? ''),
        ];
    }

    private function texto(Worksheet $hoja, string $col, int $fila, string $valor): void
    {
        $hoja->setCellValueExplicit($col.$fila, $valor, DataType::TYPE_STRING);
    }

    /**
     * Solo las filas de DATOS: formato texto en A, B y E. Ni la fila de encabezados ni los
     * anchos de la plantilla se tocan.
     */
    private function formato(Worksheet $hoja, int $ultimaFila): void
    {
        if ($ultimaFila < self::PRIMERA_FILA) {
            return;
        }

        foreach (self::COLUMNAS_TEXTO as $col) {
            $hoja->getStyle($col.self::PRIMERA_FILA.':'.$col.$ultimaFila)
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
    }

    /**
     * Los cinco datos que irían en la fila de este documento, para congelarlos en el
     * renglón de la solicitud.
     *
     * El «# ALBARAN» es SOLO el número (`5131`), no el canónico completo
     * (`AC01/0017/00/5131`): la sala y el tipo ya tienen su propia columna, y repetirlos
     * dentro del número sería mandar el mismo dato tres veces escrito de dos formas.
     *
     * @return array<string, mixed>
     */
    public static function datosDeFila(CobroDocumento $documento): array
    {
        $albaran = $documento->albaran;
        $partes = NumeroAlbaran::desde($albaran?->numero_albaran);

        return [
            'sala_codigo' => $albaran?->sala_codigo ?: $partes?->sala,
            'albaran_numero' => $partes?->numero ?? $albaran?->numero_albaran,
            'albaran_anio' => $albaran?->fecha_albaran === null ? null : (int) $albaran->fecha_albaran->format('y'),
            'albaran_mes' => $albaran?->fecha_albaran === null ? null : (int) $albaran->fecha_albaran->format('n'),
            'albaran_tipo' => $albaran?->tipo_codigo,
            'monto' => $documento->monto,
        ];
    }
}
