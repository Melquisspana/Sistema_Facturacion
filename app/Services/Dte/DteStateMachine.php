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

        return $this->aplicar($dte, $actual, $nuevo, $usuario, $comentario);
    }

    /**
     * La ÚNICA salida de «Rechazado», y solo hacia «Aceptado»: Hacienda aceptó ese mismo
     * código de generación y mandó su sello, pero la respuesta llegó después de que acá
     * se guardara un rechazo. El sello de Hacienda manda.
     *
     * Es un paso aparte y no un estado siguiente más de «Rechazado» a propósito: por
     * {@see transicionar()} un rechazado sigue sin poder ir a ningún lado. Quien decide
     * que el sello es del mismo documento es {@see DteTransmisionService}, con la fila
     * bloqueada; acá se exige lo que no se puede saltar: que esté rechazado y que el
     * sello ya esté puesto.
     *
     * @throws TransicionInvalidaException
     */
    public function aceptarTrasRechazo(Dte $dte, ?User $usuario = null, ?string $comentario = null): Dte
    {
        if ($dte->estado !== EstadoDte::Rechazado) {
            throw new TransicionInvalidaException(
                'Solo un documento rechazado se acepta por una respuesta tardía de Hacienda (estado actual: '.$dte->estado->label().').'
            );
        }

        if (blank($dte->sello_recepcion)) {
            throw new TransicionInvalidaException('Sin sello de recepción de Hacienda un documento rechazado no pasa a aceptado.');
        }

        return $this->aplicar($dte, EstadoDte::Rechazado, EstadoDte::Aceptado, $usuario, $comentario);
    }

    /** Guarda el estado nuevo y su renglón en el historial. Quien llama ya validó el paso. */
    private function aplicar(Dte $dte, EstadoDte $actual, EstadoDte $nuevo, ?User $usuario, ?string $comentario): Dte
    {
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
