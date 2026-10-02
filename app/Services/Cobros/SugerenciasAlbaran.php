<?php

namespace App\Services\Cobros;

use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Support\Albaran;
use App\Support\Dinero;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Support\Collection;

/**
 * Albaranes de entrega SUGERIDOS para vincular a mano un CCF cuyo vínculo salió mal (por
 * ejemplo, una OC mal tecleada que dedujo la sala 0620 en vez de la 0062).
 *
 * Solo ordena y explica: no vincula nada. La decisión es de una persona y pasa por
 * {@see VinculadorAlbaranes::vincularAMano()}, que comprueba otra vez todo bajo bloqueo.
 * Cada sugerido dice POR QUÉ se sugiere y qué lo contradice; uno ya tomado por otro CCF se
 * muestra (es la pista de un conflicto) pero no se puede elegir.
 */
class SugerenciasAlbaran
{
    /** Días alrededor de la emisión en los que se buscan sugeridos. */
    private const DIAS_VENTANA = 60;

    /** Lo mínimo para sugerir: una sola señal fuerte (monto, sala u OC), no solo la fecha. */
    private const PUNTAJE_MINIMO = 30;

    private const MAXIMO = 10;

    public function __construct(
        private readonly VinculadorAlbaranes $vinculador,
    ) {}

    /**
     * @return array<int, array<string, mixed>> los mejores primero; los ya tomados al final
     */
    public function para(CobroDocumento $documento): array
    {
        $consulta = $this->base($documento)
            ->when($documento->ppq_albaran_id !== null, fn ($q) => $q->whereKeyNot($documento->ppq_albaran_id));

        if ($documento->fecha_emision !== null) {
            $consulta->whereBetween('fecha_albaran', [
                $documento->fecha_emision->copy()->subDays(self::DIAS_VENTANA)->toDateString(),
                $documento->fecha_emision->copy()->addDays(self::DIAS_VENTANA)->toDateString(),
            ]);
        }

        return $this->presentar($documento, $consulta->limit(500)->get())
            ->filter(fn (array $s) => $s['puntaje'] >= self::PUNTAJE_MINIMO)
            ->sortBy([fn ($a, $b) => ($a['tomado_por'] !== null) <=> ($b['tomado_por'] !== null), ['puntaje', 'desc']])
            ->take(self::MAXIMO)
            ->values()
            ->all();
    }

    /**
     * Búsqueda por número de albarán, para el caso que no aparece entre los sugeridos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(CobroDocumento $documento, string $texto): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < 3) {
            return [];
        }

        $albaranes = $this->base($documento)
            ->where('numero_albaran', 'like', '%'.addcslashes($texto, '%_\\').'%')
            ->orderByDesc('fecha_albaran')
            ->limit(self::MAXIMO)
            ->get();

        return $this->presentar($documento, $albaranes)->values()->all();
    }

    /** Entregas que este CCF podría tener: del cliente (o sin sucursal) y sin otro DTE explícito. */
    private function base(CobroDocumento $documento)
    {
        return PpqAlbaran::query()
            ->deEntrega()
            ->where(fn ($q) => $q->whereNull('dte_id')->orWhere('dte_id', $documento->dte_id ?? 0))
            ->where(fn ($q) => $q->whereNull('cliente_sucursal_id')
                ->orWhereHas('clienteSucursal', fn ($s) => $s->where('cliente_id', $documento->cliente_id)));
    }

    /**
     * @param  Collection<int, PpqAlbaran>  $albaranes
     * @return Collection<int, array<string, mixed>>
     */
    private function presentar(CobroDocumento $documento, Collection $albaranes): Collection
    {
        $tomados = CobroDocumento::query()
            ->whereIn('ppq_albaran_id', $albaranes->pluck('id'))
            ->where('id', '!=', $documento->id)
            ->pluck('numero_control', 'ppq_albaran_id');

        $ocDocumento = OrdenCompra::normalizar($documento->dte?->numero_orden_compra);
        $salaDocumento = OrdenCompra::salaDesde($ocDocumento);

        return $albaranes->map(function (PpqAlbaran $albaran) use ($documento, $tomados, $ocDocumento, $salaDocumento) {
            $sala = $albaran->sala_codigo ?: Albaran::salaDesdeNumero($albaran->numero_albaran);
            [$puntaje, $motivos] = $this->puntuar($documento, $albaran, $sala, $ocDocumento, $salaDocumento);

            return [
                'id' => $albaran->id,
                'numero' => $albaran->numero_albaran,
                'sala' => $sala,
                'sala_nombre' => $sala === null ? 'Sala sin código' : Sala::descripcion($sala),
                'fecha' => $albaran->fecha_albaran?->format('d/m/Y'),
                'monto' => $albaran->monto_albaran === null ? null : number_format((float) $albaran->monto_albaran, 2),
                'orden_compra' => $albaran->numero_orden_compra,
                'puntaje' => $puntaje,
                'motivos' => $motivos,
                'avisos' => $this->vinculador->contradicciones($documento, $albaran),
                'tomado_por' => $tomados->get($albaran->id),
            ];
        });
    }

    /** @return array{0: int, 1: array<int, string>} */
    private function puntuar(CobroDocumento $documento, PpqAlbaran $albaran, ?string $sala, string $ocDocumento, ?string $salaDocumento): array
    {
        $puntaje = 0;
        $motivos = [];

        if ($documento->monto !== null && $albaran->monto_albaran !== null) {
            $diferencia = abs((float) Dinero::restar($documento->monto, $albaran->monto_albaran));
            if ($diferencia < 0.005) {
                $puntaje += 40;
                $motivos[] = 'mismo monto';
            } elseif ($diferencia <= (float) $documento->monto * 0.01) {
                $puntaje += 20;
                $motivos[] = 'monto casi igual (diferencia '.number_format($diferencia, 2).')';
            }
        }

        $ocAlbaran = OrdenCompra::normalizar($albaran->numero_orden_compra);
        if ($ocDocumento !== '' && $ocAlbaran !== '') {
            if ($ocAlbaran === $ocDocumento) {
                $puntaje += 40;
                $motivos[] = 'misma orden de compra';
            } elseif (levenshtein($ocAlbaran, $ocDocumento) <= 2) {
                $puntaje += 25;
                $motivos[] = 'orden de compra casi igual a la del CCF';
            }
        }

        if ($salaDocumento !== null && $sala !== null) {
            if ($sala === $salaDocumento) {
                $puntaje += 30;
                $motivos[] = 'misma sala que la orden de compra';
            } elseif (self::salasParecidas($sala, $salaDocumento)) {
                $puntaje += 25;
                $motivos[] = "sala {$sala} parecida a la {$salaDocumento} de la orden de compra";
            }
        }

        if ($documento->fecha_emision !== null && $albaran->fecha_albaran !== null) {
            $dias = (int) abs($albaran->fecha_albaran->diffInDays($documento->fecha_emision));
            $puntos = match (true) {
                $dias <= 3 => 20,
                $dias <= 15 => 10,
                $dias <= self::DIAS_VENTANA => 5,
                default => 0,
            };
            if ($puntos > 0) {
                $puntaje += $puntos;
                $motivos[] = $dias === 0 ? 'misma fecha' : "fecha a {$dias} días de la emisión";
            }
        }

        return [$puntaje, $motivos];
    }

    /**
     * Dos salas que se confunden al teclear la OC: iguales sin ceros a la izquierda, o con un
     * dígito de más, de menos o cambiado (0620 ↔ 0062, 0062 ↔ 0063).
     */
    public static function salasParecidas(string $a, string $b): bool
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        return $a !== '' && $b !== '' && levenshtein($a, $b) <= 1;
    }
}
