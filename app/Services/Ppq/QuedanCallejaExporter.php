<?php

namespace App\Services\Ppq;

use App\Enums\EstadoDte;
use App\Exceptions\Ppq\ArchivoQuedanIncompletoException;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1;
use App\Support\NumeroAlbaran;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Archivo de CARGA MASIVA de «Solicitud de Quedan» del portal de Calleja, armado
 * directamente desde un LOTE PPQ ya construido: cinco columnas, generadas a partir de la
 * plantilla original «FORMATO DE CARGA MASIVA QUEDAN.xlsx» en resources/templates/calleja.
 *
 * Es el mismo formato de portal que ya genera Cobros con
 * {@see ExportadorSolicitudCargaMasivaV1} a partir de una
 * `CobroSolicitud` registrada. Esta clase NO pasa por ahí a propósito: sirve al operador
 * que ya armó un lote PPQ y quiere el archivo del portal sin abrir otra pantalla, y no crea
 * ningún registro de presentación. Para que los dos archivos no diverjan, replica la MISMA
 * normalización de sala/número/año/mes que esa clase (ver su documentación para el porqué
 * de cada regla) en vez de reinventar una propia.
 *
 * ═══════════════ CCF y después sus NC ═══════════════
 *
 * Primero una fila por CCF con su albarán de entrega (AC01); después una por NC con su
 * albarán de crédito (AC02/AC04) tal como se registró al emitirla. Regla del usuario.
 *
 * ═══════════════ Todo o nada ═══════════════
 *
 * Los cinco datos de cada fila salen del ALBARÁN DE ENTREGA (AC01) vinculado al CCF, nunca
 * de la factura ni de un albarán de crédito. Si a un CCF le falta el albarán, el albarán no
 * es de entrega, falta la fecha, la sala/número/tipo no son confiables, hay una
 * contradicción de sala, o dos CCF resuelven al mismo albarán, el archivo COMPLETO se
 * rechaza con el detalle de qué documento lo impide ({@see ArchivoQuedanIncompletoException}).
 * No se genera un archivo con huecos ni se descarta en silencio un CCF problemático.
 */
class QuedanCallejaExporter
{
    /** Los cinco encabezados de la fila 1, EXACTAMENTE como en la plantilla del cliente. */
    private const COLUMNAS = [
        'CODIGO DE SALA O CD',
        '# ALBARAN',
        'AÑO (ultimos 2 digitos)',
        'MES  (en numero)',
        'TIPO ALBARAN',
    ];

    /** Columnas que van como TEXTO: la sala conserva su cero inicial, número y tipo son códigos. */
    private const COLUMNAS_TEXTO = ['A', 'B', 'E'];

    private const PRIMERA_FILA = 2;

    /** Nombre de la hoja en la plantilla del cliente. Se conserva tal cual. */
    private const HOJA = 'Hoja3';

    /**
     * @param  string|null  $plantilla  ruta de la plantilla; null = la incluida en el
     *                                  proyecto. Solo las pruebas la cambian.
     */
    public function __construct(private readonly ?string $plantilla = null) {}

    public function rutaPlantilla(): string
    {
        return $this->plantilla ?? resource_path('templates/calleja/FORMATO DE CARGA MASIVA QUEDAN.xlsx');
    }

    /**
     * Genera el archivo del portal desde los CCF del lote, o lanza
     * ArchivoQuedanIncompletoException con el detalle de qué documento lo impide. Es una
     * LECTURA: no modifica el lote, sus items ni sus estados.
     *
     * @throws RuntimeException|ArchivoQuedanIncompletoException
     */
    public function generar(PpqLote $lote): string
    {
        $ccf = $this->ccfDelLote($lote);

        if ($ccf->isEmpty()) {
            throw new ArchivoQuedanIncompletoException([
                'el lote no tiene comprobantes de crédito fiscal (tipo 03) vinculables a un '.
                'albarán de entrega.',
            ]);
        }

        [$filas, $motivos] = $this->resolverFilas($ccf);
        [$filasNc, $motivosNc] = $this->resolverNotas($lote, $filas);
        $filas = array_merge($filas, $filasNc);
        $motivos = array_merge($motivos, $motivosNc);

        if ($motivos !== []) {
            throw new ArchivoQuedanIncompletoException($motivos);
        }

        return $this->escribir($filas);
    }

    /**
     * Filas de las NC del lote, después de los CCF: una por NC con su albarán de crédito.
     *
     *  · Número, sala y fecha salen del albarán que tiene el item PPQ (el que se cotejó con
     *    Calleja); si no hay, del registrado al emitir la NC. El TIPO (AC02/AC04) sale de
     *    la NC, porque la copia PPQ no lo guarda. La sala confirmada a mano manda.
     *  · No van las NC invalidadas ni las que ya viajaron en un lote anterior (el portal
     *    responde «ya se encuentra asociado a un registro de quedan»).
     *
     * Regla del usuario (25/09/2026): en la solicitud de quedan también van las NC.
     *
     * @param  array<int, array{sala: string, numero: string, anio: int, mes: int, tipo: string}>  $filasCcf
     * @return array{0: array<int, array{sala: string, numero: string, anio: int, mes: int, tipo: string}>, 1: array<int, string>}
     */
    private function resolverNotas(PpqLote $lote, array $filasCcf): array
    {
        $lote->load(['items.dte.albaran', 'items.albaran']);
        $vistos = [];
        foreach ($filasCcf as $f) {
            $vistos[$f['sala'].'|'.$f['numero'].'|'.$f['tipo']] = true;
        }

        $notas = $lote->itemsOrdenados()->filter(fn (PpqItem $i) => $i->esNc());
        $yaPresentadas = PpqItem::query()
            ->where('ppq_lote_id', '<', $lote->id)
            ->whereIn('dte_id', $notas->pluck('dte_id')->filter()->all())
            ->pluck('dte_id')->flip();

        $filas = [];
        $motivos = [];
        foreach ($notas as $item) {
            if ($item->dte?->estado === EstadoDte::Invalidado || ($item->dte_id !== null && $yaPresentadas->has($item->dte_id))) {
                continue;
            }
            $control = $item->numero_control ?? $item->dte?->numero_control ?? ('#'.$item->id);
            $propio = $item->dte?->albaran;
            $partesPropio = NumeroAlbaran::desde($propio?->numero_canonico);
            $ppq = $item->albaran;
            $partesPpq = NumeroAlbaran::desde($ppq?->numero_albaran);

            $tipo = strtoupper((string) ($ppq?->tipo_codigo ?: $partesPpq?->tipo ?: $partesPropio?->tipo));
            $numero = $ppq !== null ? ($partesPpq?->numero ?? trim((string) $ppq->numero_albaran)) : $partesPropio?->numero;
            $sala = $this->salaConfirmada($item) ?? ($ppq?->sala_codigo ?: $partesPpq?->sala ?: $partesPropio?->sala);
            $fecha = $ppq?->fecha_albaran ?? ($propio?->fecha !== null ? Carbon::parse($propio->fecha) : null);

            if (! in_array($tipo, ['AC02', 'AC04', 'AC06'], true) || blank($numero) || blank($sala) || $fecha === null) {
                $motivos[] = "{$control}: la NC no tiene completo su albarán de crédito (AC02/AC04 con sala, número y fecha).";

                continue;
            }

            $fila = ['sala' => str_pad((string) $sala, 4, '0', STR_PAD_LEFT), 'numero' => (string) $numero, 'anio' => (int) $fecha->format('y'), 'mes' => (int) $fecha->format('n'), 'tipo' => $tipo];
            $clave = $fila['sala'].'|'.$fila['numero'].'|'.$fila['tipo'];
            if (isset($vistos[$clave])) {
                $motivos[] = "{$control}: su albarán {$fila['tipo']}/{$fila['sala']}/{$fila['numero']} ya está en otra fila del archivo.";

                continue;
            }
            $vistos[$clave] = true;
            $filas[] = $fila;
        }

        return [$filas, $motivos];
    }

    /**
     * Los CCF del lote, en el mismo orden del Excel de Calleja (ver PpqLote::itemsOrdenados()).
     *
     * @return Collection<int, PpqItem>
     */
    private function ccfDelLote(PpqLote $lote): Collection
    {
        $lote->loadMissing(['items.dte:id,tipo_dte,numero_control', 'items.albaran']);

        // Los tipos ajenos a 03/05 siguen aquí para que problema() bloquee el archivo
        // con un motivo visible; nunca deben salir como si fueran CCF.
        return $lote->itemsOrdenados()->reject(fn (PpqItem $i) => $i->esNc())->values();
    }

    /**
     * @param  Collection<int, PpqItem>  $ccf
     * @return array{0: array<int, array{sala: string, numero: string, anio: int, mes: int, tipo: string}>, 1: array<int, string>}
     */
    private function resolverFilas(Collection $ccf): array
    {
        $filas = [];
        $motivos = [];
        /** @var array<string, string> $vistos clave sala|numero|tipo -> control ya usado */
        $vistos = [];

        foreach ($ccf as $item) {
            $control = $item->numero_control ?? $item->dte?->numero_control ?? ('#'.$item->id);
            $problema = $this->problema($item);

            if ($problema !== null) {
                $motivos[] = "{$control}: {$problema}.";

                continue;
            }

            $datos = $this->datos($item->albaran, $this->salaConfirmada($item));
            $clave = $datos['sala'].'|'.$datos['numero'].'|'.$datos['tipo'];

            if (isset($vistos[$clave])) {
                $motivos[] = "{$control} y {$vistos[$clave]}: mismo albarán ({$datos['sala']}/{$datos['numero']}/{$datos['tipo']}) en dos CCF del lote.";

                continue;
            }
            $vistos[$clave] = $control;

            $filas[] = $datos;
        }

        return [$filas, $motivos];
    }

    /** Motivo por el que este CCF no puede entrar en el archivo, o null si está completo. */
    private function problema(PpqItem $item): ?string
    {
        if (($item->tipo_dte ?? $item->dte?->tipo_dte?->value) !== '03') {
            return 'el documento no es un CCF (tipo 03)';
        }

        if (! $item->tieneAlbaran()) {
            return 'sin albarán vinculado';
        }

        $albaran = $item->albaran;

        if (! $albaran->esDeEntrega()) {
            return 'el albarán vinculado no es de entrega (AC01): es '.($albaran->tipo_codigo ?: 'de tipo desconocido');
        }

        if ($albaran->fecha_albaran === null) {
            return 'el albarán no tiene fecha registrada';
        }

        $partes = NumeroAlbaran::desde($albaran->numero_albaran);
        $sala = $albaran->sala_codigo ?: $partes?->sala;
        $numero = $partes?->numero ?? $albaran->numero_albaran;

        // Un número suelto es válido si el tipo y la sala constan por separado.
        // Cualquier otro texto sin desglose no es un número para el portal.
        if ($partes === null && ! preg_match('/^\d{1,10}$/', trim((string) $numero))) {
            return 'el albarán no tiene un número confiable';
        }
        if ($partes !== null && $partes->tipo !== strtoupper((string) $albaran->tipo_codigo)) {
            return "contradicción de tipo: el albarán registra {$albaran->tipo_codigo} pero su número indica {$partes->tipo}";
        }

        // Excepción explícita (config ppq.quedan.salas_confirmadas): el operador confirmó
        // la sala real del CCF. Solo vale si es la sala que trae el NÚMERO del albarán, y
        // entonces reemplaza los dos cruces de sala de abajo. No toca DTE ni albarán.
        $confirmada = $this->salaConfirmada($item);
        if ($confirmada !== null) {
            if ($partes?->sala !== $confirmada) {
                return "sala confirmada {$confirmada}, pero el número del albarán indica ".($partes?->sala ?? 'otra sala');
            }

            return null;
        }

        if (blank($sala)) {
            return 'el albarán no tiene una sala confiable';
        }
        if (blank($numero)) {
            return 'el albarán no tiene un número confiable';
        }
        if (blank($albaran->tipo_codigo)) {
            return 'el albarán no tiene un tipo confiable';
        }

        // Contradicción DENTRO del propio albarán: la sala que guarda y la que trae
        // embebida en su propio número (2º segmento) no coinciden.
        if ($albaran->sala_codigo && $partes?->sala && str_pad((string) $albaran->sala_codigo, 4, '0', STR_PAD_LEFT) !== $partes->sala) {
            return "contradicción de sala: el albarán registra sala {$albaran->sala_codigo} pero su número indica {$partes->sala}";
        }

        // Contradicción entre el documento y su albarán: la sala de la OC del CCF no
        // coincide con la sala del albarán vinculado (posible albarán equivocado).
        $mismatch = $item->salaMismatch();
        if ($mismatch !== null) {
            return "contradicción de sala: {$mismatch['detalle']}";
        }

        return null;
    }

    /** Sala confirmada a mano para este CCF (por número de control), o null. */
    private function salaConfirmada(PpqItem $item): ?string
    {
        $control = strtoupper(trim((string) ($item->numero_control ?? $item->dte?->numero_control)));
        $sala = config('ppq.quedan.salas_confirmadas', [])[$control] ?? null;

        return filled($sala) ? str_pad(trim((string) $sala), 4, '0', STR_PAD_LEFT) : null;
    }

    /**
     * Los cinco datos de la fila para un albarán YA validado por {@see problema()}. Misma
     * normalización que ExportadorSolicitudCargaMasivaV1::datosDeFila().
     *
     * @return array{sala: string, numero: string, anio: int, mes: int, tipo: string}
     */
    private function datos(PpqAlbaran $albaran, ?string $salaConfirmada = null): array
    {
        $partes = NumeroAlbaran::desde($albaran->numero_albaran);

        return [
            'sala' => (string) ($salaConfirmada ?? $albaran->sala_codigo ?: $partes?->sala),
            'numero' => (string) ($partes?->numero ?? $albaran->numero_albaran),
            'anio' => (int) $albaran->fecha_albaran->format('y'),
            'mes' => (int) $albaran->fecha_albaran->format('n'),
            'tipo' => (string) $albaran->tipo_codigo,
        ];
    }

    /**
     * @param  array<int, array{sala: string, numero: string, anio: int, mes: int, tipo: string}>  $filas
     *
     * @throws RuntimeException
     */
    private function escribir(array $filas): string
    {
        $libro = $this->abrirPlantilla();
        $hoja = $libro->getSheetByName(self::HOJA);
        $libro->setActiveSheetIndex($libro->getIndex($hoja));

        $fila = self::PRIMERA_FILA;
        foreach ($filas as $datos) {
            $hoja->setCellValueExplicit('A'.$fila, $datos['sala'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('B'.$fila, $datos['numero'], DataType::TYPE_STRING);
            $hoja->setCellValue([3, $fila], $datos['anio']);
            $hoja->setCellValue([4, $fila], $datos['mes']);
            $hoja->setCellValueExplicit('E'.$fila, $datos['tipo'], DataType::TYPE_STRING);
            $fila++;
        }

        $this->formato($hoja, $fila - 1);

        // El archivo de tempnam() se usa tal cual: agregarle «.xlsx» dejaba el original
        // vacío huérfano (mismo cuidado que ExportadorSolicitudCargaMasivaV1).
        $ruta = tempnam(sys_get_temp_dir(), 'ppq_quedan_');

        if ($ruta === false) {
            $libro->disconnectWorksheets();

            throw new RuntimeException('No se pudo crear el archivo temporal del portal de quedan.');
        }

        try {
            (new Xlsx($libro))->save($ruta);
        } catch (Throwable $e) {
            @unlink($ruta);

            throw new RuntimeException('No se pudo escribir el archivo del portal de quedan: '.$e->getMessage(), 0, $e);
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

    /** Solo las filas de DATOS: formato texto en A, B y E. Encabezados y anchos intactos. */
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

    /** Los cinco encabezados de la plantilla, tal cual (para pruebas/vista previa). */
    public static function columnas(): array
    {
        return self::COLUMNAS;
    }

    /**
     * Nombre con el que viaja la descarga. Distinto, a propósito, del que usa el Excel PPQ
     * de 10 columnas ({@see ExcelCallejaExporter::nombreArchivo()}): son dos archivos con
     * destinos distintos y no deben confundirse por el nombre.
     */
    public function nombreArchivo(PpqLote $lote): string
    {
        $codigo = (string) config('ppq.codigo_proveedor', '000123');

        return $codigo.'-QUEDAN-LOTE'.$lote->id.'-'.now('America/El_Salvador')->format('YmdHi').'.xlsx';
    }
}
