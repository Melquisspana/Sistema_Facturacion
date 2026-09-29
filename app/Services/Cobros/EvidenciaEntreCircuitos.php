<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\OrigenConciliacionPpq;
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqConciliacion;
use Illuminate\Support\Carbon;

/**
 * ¿Este mismo archivo de pagos (misma huella SHA-256) ya se procesó en el OTRO circuito?
 *
 * El seguimiento de Cobros y los lotes PPQ reciben el mismo TXT del cliente y cada uno
 * evita procesarlo dos veces dentro de sí mismo, pero ninguno veía al otro. Esto solo
 * INFORMA: no bloquea, no copia pagos, no suma ni revierte nada. Los dos registros son
 * independientes y así siguen.
 *
 * Lo que se puede demostrar, y nada más:
 *
 *   · en PPQ, cada corrida con archivo queda en `ppq_conciliaciones` con su huella, aunque
 *     no haya cambiado ningún renglón;
 *   · en Cobros NO existe un registro del archivo en sí: su huella solo queda en los
 *     EVENTOS de pago y en los AJUSTES que generó. Un archivo aplicado en Cobros cuyas
 *     filas no identificaron ningún documento ni traían ajustes no dejó rastro, así que
 *     «no se encontró en Cobros» no prueba que nunca se cargó allí.
 */
class EvidenciaEntreCircuitos
{
    /**
     * Corridas PPQ que ya procesaron este archivo, de la más vieja a la más reciente.
     *
     * @return array<int, array{lote_id: int, referencia: ?string, lote_disponible: bool, fecha: ?Carbon}>
     */
    public function enPpq(string $hash): array
    {
        return PpqConciliacion::query()
            ->where('archivo_hash', $hash)
            ->where('origen', OrigenConciliacionPpq::Txt->value)
            ->with('lote:id,referencia')
            ->orderBy('id')
            ->get(['id', 'ppq_lote_id', 'created_at'])
            ->map(fn (PpqConciliacion $c) => [
                'lote_id' => (int) $c->ppq_lote_id,
                'referencia' => $c->lote?->referencia,
                'lote_disponible' => $c->lote !== null,
                'fecha' => $c->created_at,
            ])
            ->all();
    }

    /**
     * Rastro del archivo en el seguimiento de Cobros: pagos registrados y ajustes, con la
     * primera fecha en que se registraron. Null si no dejó ningún rastro demostrable.
     *
     * @return array{pagos: int, documentos: int, ajustes: int, primera: ?Carbon}|null
     */
    public function enCobros(string $hash): ?array
    {
        $pagos = CobroEvento::query()
            ->where('tipo', TipoEventoCobro::Pago->value)
            ->where('origen', 'txt')
            ->where('evidencia_hash', $hash)
            ->selectRaw('COUNT(*) as pagos, COUNT(DISTINCT cobro_documento_id) as documentos, MIN(created_at) as primera')
            ->first();

        $ajustes = CobroAjuste::query()
            ->where('evidencia_hash', $hash)
            ->selectRaw('COUNT(*) as ajustes, MIN(created_at) as primera')
            ->first();

        $cantidadPagos = (int) ($pagos->pagos ?? 0);
        $cantidadAjustes = (int) ($ajustes->ajustes ?? 0);

        if ($cantidadPagos === 0 && $cantidadAjustes === 0) {
            return null;
        }

        $fechas = array_filter([$pagos->primera ?? null, $ajustes->primera ?? null]);

        return [
            'pagos' => $cantidadPagos,
            'documentos' => (int) ($pagos->documentos ?? 0),
            'ajustes' => $cantidadAjustes,
            'primera' => $fechas === [] ? null : Carbon::parse(min($fechas)),
        ];
    }
}
