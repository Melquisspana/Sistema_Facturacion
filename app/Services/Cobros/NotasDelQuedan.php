<?php

namespace App\Services\Cobros;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNc;
use App\Services\Ppq\NcExportacionService;
use Illuminate\Support\Collection;

/**
 * Las NOTAS DE CRÉDITO que tiene que llevar un paquete de quedan.
 *
 * Calleja no paga un CCF subido solo: en su portal se carga PRIMERO el archivo de NC
 * (AC02/AC04) y DESPUÉS el de quedan (AC01). Esta clase responde, para los CCF elegidos,
 * qué notas tienen, cuáles cuentan y si ya se registró su carga al portal.
 *
 * Solo por el vínculo persistido `dtes.dte_relacionado_id`. Nunca por OC, importe, fecha
 * ni texto. Un CCF externo (sin DTE local) no tiene ese vínculo, y por eso NO se afirma que
 * no tenga notas: se dice que no se pudo verificar.
 *
 * Solo lee. No crea lotes, no marca nada y no toca valores fiscales.
 */
class NotasDelQuedan
{
    /** NC no aceptada realmente por Hacienda: no se exporta ni descuenta; solo se avisa. */
    public const AVISO = 'aviso';

    /** Aceptada, sin lote, y le falta algo para su fila del formato. */
    public const BLOQUEADA = 'bloqueada';

    /** Aceptada, completa y todavía en ningún lote. */
    public const POR_EXPORTAR = 'por_exportar';

    /** Aceptada y en un lote, pero nadie registró haber cargado ese lote al portal. */
    public const SIN_PRESENTAR = 'sin_presentar';

    /** Aceptada y en un lote cuya carga al portal está registrada. */
    public const PRESENTADA = 'presentada';

    public function __construct(
        private readonly NcExportacionService $exportaciones,
        private readonly PerfilDocumentoResolver $perfiles,
    ) {}

    /**
     * Estado de las NC de cada CCF. Tres consultas en total, no una por CCF ni por nota.
     *
     * @param  Collection<int, CobroDocumento>  $documentos
     * @return array{
     *     documentos: array<int, array{documento: CobroDocumento, verificable: bool, motivo: ?string, notas: array<int, array<string, mixed>>}>,
     *     por_exportar: array<int, int>,
     *     bloqueadas: array<int, array<string, mixed>>,
     *     sin_presentar: array<int, array<string, mixed>>,
     *     presentadas: array<int, array<string, mixed>>,
     *     avisos: int,
     *     no_verificables: array<int, CobroDocumento>,
     *     completo: bool,
     *     huella: array<int, mixed>,
     * }
     */
    public function analizar(Cliente $cliente, Collection $documentos): array
    {
        $dteIds = $documentos->pluck('dte_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $ccfLocales = $dteIds === [] ? [] : Dte::query()
            ->whereIn('id', $dteIds)
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $notasPorCcf = $ccfLocales === [] ? collect() : Dte::query()
            ->whereIn('dte_relacionado_id', $ccfLocales)
            ->where('tipo_dte', TipoDte::NotaCredito->value)
            ->with(['albaran', 'exportacionItem.exportacion'])
            ->orderBy('fecha_emision')
            ->orderBy('numero_control')
            ->orderBy('id')
            ->get()
            ->groupBy('dte_relacionado_id');

        $exportador = $this->exportaciones->exportador($cliente);
        $perfil = $this->perfiles->paraCliente($cliente->id);

        $resultado = [
            'documentos' => [],
            'por_exportar' => [],
            'bloqueadas' => [],
            'sin_presentar' => [],
            'presentadas' => [],
            'avisos' => 0,
            'no_verificables' => [],
            'completo' => true,
            'huella' => [],
        ];

        foreach ($documentos as $documento) {
            $dteId = $documento->dte_id !== null ? (int) $documento->dte_id : null;

            if ($dteId === null || ! in_array($dteId, $ccfLocales, true)) {
                $resultado['documentos'][$documento->id] = [
                    'documento' => $documento,
                    'verificable' => false,
                    'motivo' => $dteId === null
                        ? 'CCF externo, sin DTE en este sistema: no se puede saber desde aquí si tiene NC. '
                            .'La relación con sus NC queda pendiente de la captura masiva de Gmail.'
                        : 'Su DTE ya no está disponible en este sistema: no se pueden leer sus NC.',
                    'notas' => [],
                ];
                $resultado['no_verificables'][] = $documento;
                $resultado['huella'][] = [$documento->id, false, []];

                continue;
            }

            $filas = [];
            foreach ($notasPorCcf->get($dteId, collect()) as $nc) {
                $fila = $this->fila($cliente, $documento, $nc, $exportador, $perfil);
                $filas[] = $fila;

                match ($fila['situacion']) {
                    self::AVISO => $resultado['avisos']++,
                    self::BLOQUEADA => $resultado['bloqueadas'][] = $fila,
                    self::POR_EXPORTAR => $resultado['por_exportar'][] = $nc->id,
                    self::SIN_PRESENTAR => $resultado['sin_presentar'][] = $fila,
                    self::PRESENTADA => $resultado['presentadas'][] = $fila,
                };
            }

            $resultado['documentos'][$documento->id] = [
                'documento' => $documento,
                'verificable' => true,
                'motivo' => null,
                'notas' => $filas,
            ];
            $resultado['huella'][] = [$documento->id, true, array_map(fn (array $f) => [
                $f['nc']->id,
                $f['nc']->estado?->value,
                $f['aceptada'],
                $f['nc']->albaran?->id,
                $f['nc']->albaran?->numero_canonico,
                $f['nc']->albaran?->tipo_codigo,
                $f['nc']->albaran?->sala_codigo,
                $f['nc']->albaran?->numero,
                $f['nc']->albaran?->fecha?->toDateString(),
                $f['nc']->albaran?->total,
                $f['nc']->codigo_generacion,
                $f['lote']?->id,
                $f['lote']?->presentada_en?->toIso8601String(),
            ], $filas)];
        }

        $resultado['completo'] = $resultado['bloqueadas'] === []
            && $resultado['por_exportar'] === []
            && $resultado['sin_presentar'] === []
            && $resultado['no_verificables'] === [];

        return $resultado;
    }

    /**
     * Qué impide preparar el quedan, redactado para el operador. Vacío = nada.
     *
     * @param  array<string, mixed>  $analisis  resultado de {@see analizar()}
     * @return array<int, string>
     */
    public static function bloqueos(array $analisis): array
    {
        $bloqueos = [];

        foreach ($analisis['bloqueadas'] as $fila) {
            $bloqueos[] = $fila['nc']->numero_control.' (CCF '.$fila['documento']->numero_control.'): falta '
                .implode(', ', $fila['faltantes']).'.';
        }
        if ($analisis['por_exportar'] !== []) {
            $bloqueos[] = count($analisis['por_exportar']).' NC aceptada(s) todavía no están en ningún archivo de NC: '
                .'prepárelas, súbalas al portal y registre la carga.';
        }
        foreach ($analisis['sin_presentar'] as $fila) {
            $bloqueos[] = $fila['nc']->numero_control.' está en el lote '.$fila['lote']->referencia
                .', pero no hay registro de que ese archivo se haya cargado al portal.';
        }
        if ($analisis['no_verificables'] !== []) {
            $bloqueos[] = count($analisis['no_verificables']).' CCF sin relación de NC verificable en este sistema. '
                .'Incorpore sus DTE y notas desde Gmail antes de preparar el quedan; un aviso no confirma '
                .'que se hayan incluido todos los descuentos.';
        }

        return $bloqueos;
    }

    /** @return array<string, mixed> */
    private function fila(Cliente $cliente, CobroDocumento $documento, Dte $nc, ?ExportadorNc $exportador, ?ClientePerfilDocumento $perfil): array
    {
        $aceptada = $nc->aceptadoRealmentePorMh();
        $lote = $nc->exportacionItem?->exportacion;
        $faltantes = [];

        if ((int) $nc->cliente_id !== (int) $cliente->id) {
            $faltantes = ['que la nota pertenezca al mismo cliente que el CCF'];
            $situacion = self::BLOQUEADA;
        } elseif (! $aceptada) {
            $situacion = self::AVISO;
        } elseif ($lote !== null) {
            // Ya viajó en un lote: su fila quedó congelada ahí. No se regenera ni se duplica.
            $situacion = $lote->presentada() ? self::PRESENTADA : self::SIN_PRESENTAR;
        } else {
            $faltantes = $this->faltantes($cliente, $nc, $exportador, $perfil);
            $situacion = $faltantes === [] ? self::POR_EXPORTAR : self::BLOQUEADA;
        }

        return [
            'documento' => $documento,
            'nc' => $nc,
            'aceptada' => $aceptada,
            'estado' => $this->estadoFiscal($nc),
            'albaran' => $nc->albaran,
            'importe' => (string) ($nc->total_pagar ?? '0'),
            'lote' => $lote,
            'faltantes' => $faltantes,
            'situacion' => $situacion,
        ];
    }

    /** @return array<int, string> */
    private function faltantes(Cliente $cliente, Dte $nc, ?ExportadorNc $exportador, ?ClientePerfilDocumento $perfil): array
    {
        $faltan = [];

        if ((int) $nc->cliente_id !== (int) $cliente->id) {
            $faltan[] = 'que la nota sea de este cliente (está a nombre de otro)';
        }
        if ($nc->albaran === null) {
            $faltan[] = 'su albarán propio de crédito (AC02/AC04)';
        } elseif (! in_array(strtoupper(trim((string) $nc->albaran->tipo_codigo)), ['AC02', 'AC04'], true)) {
            $faltan[] = 'un tipo de albarán de crédito AC02 o AC04';
        }
        if ($exportador === null || $perfil === null) {
            $faltan[] = 'un formato de NC configurado para el cliente';
        } elseif ($nc->albaran !== null) {
            array_push($faltan, ...$exportador->faltantes($nc, $perfil));
        }

        return $faltan;
    }

    private function estadoFiscal(Dte $nc): string
    {
        if ($nc->aceptadoRealmentePorMh()) {
            return 'Aceptada por Hacienda';
        }

        return match ($nc->estado) {
            EstadoDte::Invalidado => 'Invalidada: no acredita',
            EstadoDte::Rechazado => 'Rechazada por Hacienda',
            EstadoDte::Aceptado => 'Sin aceptación real de Hacienda',
            default => 'Aún no aceptada ('.($nc->estado?->label() ?? 'sin estado').')',
        };
    }
}
