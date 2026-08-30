<?php

namespace App\Services\Ppq;

use App\Enums\OrigenConciliacionPpq;
use App\Models\PpqConciliacion;
use App\Models\PpqConciliacionMovimiento;
use App\Models\PpqItem;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quitar un cobro ya registrado. La ÚNICA forma de hacerlo.
 *
 * ─────────────────────────── Por qué existe esta clase ───────────────────────────
 *
 * Antes esto no era una acción: era un efecto colateral. Cada vez que se conciliaba un
 * lote, todo renglón que no apareciera en el archivo quedaba con el estado, la fecha y el
 * importe en NULL. Subir un TXT parcial o equivocado borraba pagos reales sin que nadie
 * lo pidiera, sin dejar rastro y sin forma de deshacerlo.
 *
 * Desde que {@see ConciliadorPpq} solo escribe sobre lo que el archivo nombra, quitar un
 * pago dejó de ocurrir solo. Y como a veces hace falta —el cliente reporta un abono que
 * después reversa, o el archivo del lote equivocado se aplicó antes de que esto
 * existiera— hace falta una puerta. Esta es esa puerta, y tiene las tres cosas que la
 * anterior no tenía:
 *
 *   · es EXPLÍCITA — alguien la pide para un renglón concreto, no le pasa a un conjunto;
 *   · es AUTORIZADA — cuelga de su propio permiso, no del de agregar items a un lote;
 *   · es AUDITADA — deja motivo obligatorio, usuario, fecha y el valor que tenía antes,
 *     en la misma bitácora que las corridas de archivo.
 *
 * El motivo es obligatorio a propósito y no tiene valor por defecto. Un pago que se
 * deshace sin explicación es indistinguible de un error, y el día que el saldo no cuadre
 * la pregunta va a ser exactamente esa.
 *
 * No borra el renglón ni lo saca del lote: lo devuelve a «pendiente». El documento sigue
 * presentado y vuelve a contar como algo por cobrar, que es lo que significa haber
 * revertido su pago.
 */
class ReversionConciliacion
{
    private const MOTIVO_MINIMO = 10;

    /**
     * Devuelve el renglón a pendiente y deja constancia.
     *
     * @throws ValidationException si el renglón no tiene nada que revertir o el motivo no
     *                             alcanza para explicar la decisión
     */
    public function revertir(PpqItem $item, string $motivo, ?User $usuario = null): PpqConciliacion
    {
        $motivo = trim($motivo);

        if (! $item->estaConciliado()) {
            throw ValidationException::withMessages([
                'motivo' => 'Ese renglón no tiene ningún cobro registrado: no hay nada que revertir.',
            ]);
        }

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw ValidationException::withMessages([
                'motivo' => 'Explicá por qué se quita este cobro (al menos '.self::MOTIVO_MINIMO.' caracteres). '
                    .'Queda registrado con tu nombre y es lo que va a explicar la diferencia más adelante.',
            ]);
        }

        return DB::transaction(function () use ($item, $motivo, $usuario) {
            $anterior = [
                'estado' => $item->conciliacion_estado,
                'fecha' => $item->fecha_pago?->toDateString(),
                // Con BCMath, igual que el resto de lo monetario: este importe es lo único
                // que va a quedar del cobro que se está deshaciendo, así que tiene que ser
                // exactamente el que estaba guardado.
                'monto' => $item->monto_pagado === null ? null : Dinero::redondear($item->monto_pagado),
            ];

            $item->forceFill([
                'conciliacion_estado' => null,
                'fecha_pago' => null,
                'monto_pagado' => null,
                'conciliado_en' => null,
            ])->save();

            $corrida = PpqConciliacion::create([
                'ppq_lote_id' => $item->ppq_lote_id,
                'user_id' => $usuario?->id,
                'origen' => OrigenConciliacionPpq::Reversion,
                // Sin archivo: una corrección no viene de ningún documento del cliente.
                // El hash en NULL además la deja fuera del único que protege el reproceso,
                // así que un lote admite todas las correcciones que haga falta.
                'items_cambiados' => 1,
                'items_sin_cambio' => 0,
                'motivo' => $motivo,
            ]);

            PpqConciliacionMovimiento::create([
                'ppq_conciliacion_id' => $corrida->id,
                'ppq_item_id' => $item->id,
                'estado_anterior' => $anterior['estado'],
                'estado_nuevo' => null,
                'fecha_pago_anterior' => $anterior['fecha'],
                'fecha_pago_nueva' => null,
                'monto_pagado_anterior' => $anterior['monto'],
                'monto_pagado_nuevo' => null,
            ]);

            activity('ppq_conciliacion')
                ->performedOn($item->lote)
                ->causedBy($usuario)
                ->withProperties([
                    'conciliacion_id' => $corrida->id,
                    'ppq_item_id' => $item->id,
                    'numero_control' => $item->numero_control ?? $item->dte?->numero_control,
                    'estado_anterior' => $anterior['estado'],
                    'monto_anterior' => $anterior['monto'],
                    'motivo' => $motivo,
                ])
                ->log('revirtió el cobro registrado de un documento del lote');

            return $corrida;
        });
    }
}
