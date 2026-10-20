<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\User;
use App\Support\Dte\CodigoGeneracion;
use App\Support\HoraNegocio;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContingenciaService
{
    /**
     * Emisión en hora de El Salvador (fecha_emision/hora_emision, origen de
     * fecEmi/horEmi del JSON), no updated_at ni la hora del último reintento.
     * El límite es estricto, igual que el tope de conversión automática.
     */
    public function documentosParaRevision(): Builder
    {
        $limite = HoraNegocio::ahora()->subMinutes(max(1, (int) config('dte.contingencia.retroceso_max_minutos', 10)));

        return Dte::ambienteOperativoActual()
            ->where('estado', EstadoDte::Firmado->value)
            ->where(fn (Builder $q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
            ->whereNull('contingencia_id')
            ->where(fn (Builder $q) => $q->whereDate('fecha_emision', '<', $limite->toDateString())
                ->orWhere(fn (Builder $q) => $q->whereDate('fecha_emision', $limite->toDateString())
                    ->where('hora_emision', '<', $limite->toTimeString())));
    }

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
            if (Contingencia::where('estado', 'activa')->first()) {
                throw ValidationException::withMessages(['contingencia' => 'Ya hay una contingencia activa. Terminá la anterior antes de activar otra.']);
            }
            try {
                $contingencia = Contingencia::create([
                    'tipo' => $tipo, 'motivo' => $motivo, 'origen' => $origen,
                    'inicio' => HoraNegocio::ahora(), 'estado' => 'activa', 'activa_unica' => 1,
                    'activada_por' => $usuario?->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages(['contingencia' => 'Ya hay una contingencia activa. Terminá la anterior antes de activar otra.']);
            }
            activity('dte_contingencia')->performedOn($contingencia)->causedBy($usuario)
                ->withProperties($contingencia->only(['tipo', 'motivo', 'origen', 'inicio']))->log('Contingencia activada');

            return $contingencia;
        });
    }

    public function terminar(?User $usuario, ?CarbonInterface $cese = null): Contingencia
    {
        $this->verificarDisponible();

        return DB::transaction(function () use ($usuario, $cese) {
            $contingencia = Contingencia::where('estado', 'activa')->lockForUpdate()->first();
            if (! $contingencia) {
                throw ValidationException::withMessages(['contingencia' => 'No hay una contingencia activa.']);
            }
            $fecha = $cese ? HoraNegocio::aLocal($cese) : HoraNegocio::ahora();
            if ($fecha->lt($contingencia->inicio) || $fecha->gt(HoraNegocio::ahora())) {
                throw ValidationException::withMessages(['cese' => 'El cese debe estar entre el inicio de la contingencia y la hora actual.']);
            }
            $contingencia->update(['cese' => $fecha, 'estado' => 'cerrada', 'activa_unica' => null, 'cerrada_por' => $usuario?->id]);
            activity('dte_contingencia')->performedOn($contingencia)->causedBy($usuario)
                ->withProperties(['cese' => $fecha])->log('Contingencia terminada');

            return $contingencia;
        });
    }

    public function eventoEnviado(Contingencia $contingencia): bool
    {
        return $contingencia->eventos()->get()->contains(fn ($evento) => $evento->fecha_transmision !== null
            || filled($evento->jws_path)
            || ($evento->json_path && Storage::disk(config('dte.storage.disk', 'local'))->exists(substr($evento->json_path, 0, -5).'.jws')));
    }

    public function corregir(Contingencia $contingencia, array $datos, ?User $usuario): Contingencia
    {
        $this->verificarDisponible();

        return DB::transaction(function () use ($contingencia, $datos, $usuario) {
            $actual = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
            if ($this->eventoEnviado($actual)) {
                throw ValidationException::withMessages(['contingencia' => 'El evento ya se intentó enviar; no se pueden cambiar sus datos.']);
            }
            Validator::make($datos, ['tipo' => ['sometimes', 'required', 'integer', 'between:1,5'],
                'motivo' => ['sometimes', 'nullable', 'string', 'max:500'], 'cese' => ['sometimes', 'required', 'date']])->validate();
            $cambios = array_intersect_key($datos, array_flip(['tipo', 'motivo', 'cese']));
            $tipo = (int) ($datos['tipo'] ?? $actual->tipo);
            $motivo = array_key_exists('motivo', $datos) ? $datos['motivo'] : $actual->motivo;
            $cambiaCabecera = $tipo !== $actual->tipo || $motivo !== $actual->motivo;
            if ($tipo === 5 && blank($motivo)) {
                throw ValidationException::withMessages(['motivo' => 'El tipo 5 requiere un motivo.']);
            }
            if ($cambiaCabecera
                && $actual->dtes()->where(fn ($q) => $q->whereNotNull('json_firmado_path')->orWhereIn('estado',
                    [EstadoDte::Firmado->value, EstadoDte::Enviado->value, EstadoDte::Aceptado->value, EstadoDte::Rechazado->value]))->exists()) {
                throw ValidationException::withMessages(['contingencia' => 'No se puede corregir el tipo o motivo: hay documentos firmados con esos datos.']);
            }
            if (isset($datos['cese'])) {
                $cese = Carbon::parse($datos['cese'], HoraNegocio::zona());
                if (! $actual->cese || $cese->lt($actual->inicio) || $cese->gt(HoraNegocio::ahora())) {
                    throw ValidationException::withMessages(['cese' => 'El cese debe estar entre el inicio y la hora actual, en una contingencia terminada.']);
                }
                $cambios['cese'] = $cese;
            }
            // Descarta solo preparaciones sin firma ni intento de envío. La evidencia
            // en disco se conserva; el próximo envío serializa los datos corregidos.
            foreach ($actual->eventos()->get() as $evento) {
                Dte::where('contingencia_evento_id', $evento->id)->update(['contingencia_evento_id' => null]);
                $evento->delete();
            }
            $actual->update($cambios);
            if ($cambiaCabecera) {
                foreach ($actual->dtes()->get() as $documento) {
                    $cabecera = ['tipo_modelo' => 2, 'tipo_operacion' => 2,
                        'tipo_contingencia' => $tipo, 'motivo_contingencia' => $motivo];
                    if (filled($documento->json_generado_path)) {
                        $json = json_decode(Storage::disk(config('dte.storage.disk', 'local'))->get($documento->json_generado_path), true, 512, JSON_THROW_ON_ERROR);
                        $json['identificacion'] = array_replace($json['identificacion'], ['tipoModelo' => 2,
                            'tipoOperacion' => 2, 'tipoContingencia' => $tipo, 'motivoContin' => $motivo]);
                        $validacion = app(DteSchemaValidator::class)->validar($json, $documento->tipo_dte);
                        if (! $validacion['valido']) {
                            throw ValidationException::withMessages(['contingencia' => 'No se pudo actualizar el JSON sin firma: '.implode(' | ', $validacion['errores'])]);
                        }
                        $ruta = 'dte/contingencia/'.$actual->id.'/correccion-'.$documento->id.'-'.CodigoGeneracion::generar().'.json';
                        app(ContingenciaTransporte::class)->guardar($ruta, json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                        $cabecera['json_generado_path'] = $ruta;
                        $cabecera['pdf_path'] = null;
                    }
                    Dte::whereKey($documento->id)->update($cabecera);
                }
            }
            activity('dte_contingencia')->performedOn($actual)->causedBy($usuario)
                ->withProperties($cambios)->log('Datos de contingencia corregidos');

            return $actual;
        });
    }

    private function verificarDisponible(): void
    {
        abort_unless(config('dte.contingencia.enabled', false) && Schema::hasTable('contingencias'), 404);
    }
}
