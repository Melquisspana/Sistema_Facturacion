<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\TransicionInvalidaException;
use App\Models\Dte;
use App\Models\DteEstadoHistorial;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Única vía válida para cambiar el estado de un DTE. Valida la transición contra
 * EstadoDte::siguientesEstados() y registra cada cambio en dte_estado_historial.
 *
 * No consume correlativos, no genera número de control, no toca Hacienda.
 */
class DteStateMachine
{
    /**
     * @throws TransicionInvalidaException
     */
    public function transicionar(Dte $dte, EstadoDte $nuevo, ?User $usuario = null, ?string $comentario = null): Dte
    {
        $actual = $dte->estado;

        if (! $actual->puedeTransicionarA($nuevo)) {
            throw new TransicionInvalidaException(
                "Transición no permitida: {$actual->label()} → {$nuevo->label()}."
            );
        }

        $usuarioId = $usuario?->id ?? Auth::id();

        DB::transaction(function () use ($dte, $actual, $nuevo, $usuarioId, $comentario) {
            $dte->estado = $nuevo;
            $dte->save();

            DteEstadoHistorial::create([
                'dte_id' => $dte->id,
                'estado_anterior' => $actual->value,
                'estado_nuevo' => $nuevo->value,
                'user_id' => $usuarioId,
                'comentario' => $comentario,
            ]);
        });

        return $dte;
    }

    /**
     * Registra el estado inicial de un borrador recién creado (sin transición).
     */
    public function registrarCreacion(Dte $dte, ?User $usuario = null, ?string $comentario = 'Creación del borrador'): void
    {
        DteEstadoHistorial::create([
            'dte_id' => $dte->id,
            'estado_anterior' => null,
            'estado_nuevo' => $dte->estado->value,
            'user_id' => $usuario?->id ?? Auth::id(),
            'comentario' => $comentario,
        ]);
    }
}
