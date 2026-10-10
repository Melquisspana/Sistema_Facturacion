<?php

namespace App\Services\Dte;

use App\Models\Contingencia;
use App\Models\User;
use App\Support\HoraNegocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContingenciaService
{
    public function activa(): ?Contingencia
    {
        if (! config('dte.contingencia.enabled', false) || ! Schema::hasTable('contingencias')) {
            return null;
        }

        return Contingencia::where('estado', 'activa')->first();
    }

    public function activar(int $tipo, ?string $motivo, string $origen, ?User $usuario): Contingencia
    {
        $this->verificarDisponible();
        Validator::make(compact('tipo', 'motivo', 'origen'), [
            'tipo' => ['required', 'integer', 'between:1,5'],
            'motivo' => ['nullable', 'required_if:tipo,5', 'string', 'max:500'],
            'origen' => ['required', 'in:manual,automatica'],
        ])->validate();

        return DB::transaction(function () use ($tipo, $motivo, $origen, $usuario) {
            $this->bloquear();
            if (Contingencia::where('estado', 'activa')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['contingencia' => 'Ya hay una contingencia activa. Terminá la anterior antes de activar otra.']);
            }
            $contingencia = Contingencia::create([
                'tipo' => $tipo, 'motivo' => $motivo, 'origen' => $origen,
                'inicio' => HoraNegocio::ahora(), 'estado' => 'activa', 'activada_por' => $usuario?->id,
            ]);
            activity('dte_contingencia')->performedOn($contingencia)->causedBy($usuario)
                ->withProperties($contingencia->only(['tipo', 'motivo', 'origen', 'inicio']))->log('Contingencia activada');

            return $contingencia;
        });
    }

    public function terminar(?User $usuario, ?CarbonInterface $cese = null): Contingencia
    {
        $this->verificarDisponible();

        return DB::transaction(function () use ($usuario, $cese) {
            $this->bloquear();
            $contingencia = Contingencia::where('estado', 'activa')->lockForUpdate()->first();
            if (! $contingencia) {
                throw ValidationException::withMessages(['contingencia' => 'No hay una contingencia activa.']);
            }
            $fecha = $cese ? HoraNegocio::aLocal($cese) : HoraNegocio::ahora();
            if ($fecha->lt($contingencia->inicio) || $fecha->gt(HoraNegocio::ahora())) {
                throw ValidationException::withMessages(['cese' => 'El cese debe estar entre el inicio de la contingencia y la hora actual.']);
            }
            $contingencia->update(['cese' => $fecha, 'estado' => 'cerrada', 'cerrada_por' => $usuario?->id]);
            activity('dte_contingencia')->performedOn($contingencia)->causedBy($usuario)
                ->withProperties(['cese' => $fecha])->log('Contingencia terminada');

            return $contingencia;
        });
    }

    private function verificarDisponible(): void
    {
        abort_unless(config('dte.contingencia.enabled', false) && Schema::hasTable('contingencias'), 404);
    }

    private function bloquear(): void
    {
        // Fila existente y común incluso antes de la primera contingencia. Bloquear
        // solamente un SELECT de activas vacío no serializa inserciones en MySQL.
        User::select('id')->orderBy('id')->lockForUpdate()->firstOrFail();
    }
}
