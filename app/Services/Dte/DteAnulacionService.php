<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\MotivoAnulacion;
use App\Exceptions\Dte\AnulacionException;
use App\Models\Dte;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Anulación INTERNA/preliminar de un documento generado: lo pasa a estado
 * invalidado, guarda motivo/observación/fecha/usuario y registra el historial.
 *
 * No borra líneas ni totales, no consume/devuelve correlativo, no toca inventario
 * y NO emite el evento oficial de invalidación ante el MH (pendiente de schema).
 */
class DteAnulacionService
{
    public function __construct(private readonly DteStateMachine $maquina) {}

    /**
     * @throws AnulacionException
     */
    public function anular(Dte $dte, MotivoAnulacion $motivo, ?string $observacion = null, ?User $usuario = null): Dte
    {
        return DB::transaction(function () use ($dte, $motivo, $observacion, $usuario) {
            // Con la fila bloqueada y releída: otro pedido pudo firmar o transmitir este
            // documento después de que este lo cargó. Con lo que traía en memoria se
            // anulaba por dentro un documento que Hacienda estaba aceptando.
            $actual = Dte::whereKey($dte->id)->lockForUpdate()->first();
            if ($actual !== null) {
                $dte->setRawAttributes($actual->getAttributes(), true);
            }

            if ($actual === null || $dte->estado !== EstadoDte::Generado) {
                throw new AnulacionException(
                    'Solo se puede anular un documento generado (estado actual: '.$dte->estado->label().').'
                );
            }

            // Aún en estado generado: el observer permite estos campos de anulación.
            $dte->motivo_anulacion = $motivo->value;
            $dte->observacion_anulacion = $observacion;
            $dte->fecha_anulacion = now();
            $dte->invalidado_by = $usuario?->id ?? Auth::id();
            $dte->save();

            $this->maquina->transicionar(
                $dte,
                EstadoDte::Invalidado,
                $usuario,
                'Anulación interna: '.$motivo->label().($observacion ? ' — '.$observacion : '')
            );

            return $dte->refresh();
        });
    }
}
