<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaCierre;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CierreDia
{
    public function entregas(SalidaRuta $salida, string $fecha): Collection
    {
        return $salida->entregas()->entregadas()->whereDate('fecha_resultado', $fecha)
            ->with(['sala', 'dte.establecimiento.empresa'])->orderBy('cliente_sucursal_id')->orderBy('id')->get();
    }

    /** Calcula en centavos: ninguna suma monetaria depende de aritmética flotante. */
    public static function centavos(string|int|float $monto): int
    {
        $partes = explode('.', (string) $monto);

        return ((int) $partes[0] * 100) + (int) str_pad(substr($partes[1] ?? '', 0, 2), 2, '0');
    }

    public static function decimal(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    public function cerrar(SalidaRuta $salida, string $fecha, array $datos, User $usuario): SalidaRutaCierre
    {
        return DB::transaction(function () use ($salida, $fecha, $datos, $usuario) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            // Una salida ya terminada todavía se liquida: la oficina suele terminarla al volver,
            // antes o después de cerrar el último día. Solo una cancelada no se cierra.
            abort_unless(in_array($salida->estado, [EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada], true), 422, 'Una salida cancelada no se liquida.');
            $cierre = $salida->cierres()->whereDate('fecha', $fecha)->lockForUpdate()->first();
            if ($cierre?->estaCerrado()) {
                throw ValidationException::withMessages(['cierre' => 'El día ya está cerrado. Reabrilo antes de corregirlo.']);
            }
            $entregas = $salida->entregas()->entregadas()->whereDate('fecha_resultado', $fecha)->lockForUpdate()->get();
            if ($entregas->isEmpty() && $cierre === null) {
                throw ValidationException::withMessages(['cierre' => 'No hay entregas registradas en esa fecha.']);
            }
            $conCobro = $entregas->whereNotNull('monto_esperado');
            $reglas = [
                'entregas' => ['sometimes', 'array'],
                'entrega_dinero_personal_id' => ['nullable', 'integer', Rule::in($salida->personal()->where('activo', true)->pluck('rutas_personal.id')->all())],
            ];
            foreach ($conCobro as $entrega) {
                $reglas["entregas.{$entrega->id}.monto_cobrado"] = ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'min:0', 'max:'.$entrega->monto_esperado];
                $reglas["entregas.{$entrega->id}.forma_cobro"] = ['required', Rule::enum(FormaCobroEntrega::class)];
            }
            $validados = Validator::make($datos, $reglas)->validate();
            $ids = array_map('strval', $conCobro->modelKeys());
            if (array_diff(array_map('strval', array_keys($datos['entregas'] ?? [])), $ids)) {
                throw ValidationException::withMessages(['entregas' => 'Solo se pueden corregir cobros de las entregas de este día.']);
            }
            $totales = [];
            foreach (FormaCobroEntrega::cases() as $forma) {
                $totales[$forma->columna().'_esperado'] = 0;
                $totales[$forma->columna().'_cobrado'] = 0;
            }
            foreach ($conCobro as $entrega) {
                $fila = $validados['entregas'][$entrega->id];
                $forma = FormaCobroEntrega::from($fila['forma_cobro']);
                $cobrado = self::centavos($fila['monto_cobrado']);
                $totales[$forma->columna().'_esperado'] += self::centavos($entrega->monto_esperado);
                $totales[$forma->columna().'_cobrado'] += $cobrado;
                $entrega->update(['forma_cobro' => $forma, 'monto_cobrado' => self::decimal($cobrado)]);
            }
            $hayCobro = $totales['efectivo_cobrado'] + $totales['cheques_cobrado'] + $totales['transferencias_cobrado'] > 0;
            if ($hayCobro && empty($validados['entrega_dinero_personal_id'])) {
                throw ValidationException::withMessages(['entrega_dinero_personal_id' => 'Elegí quién entrega el dinero entre los participantes activos de la salida.']);
            }
            foreach ($totales as $total) {
                if ($total > 999999999999) {
                    throw ValidationException::withMessages(['cierre' => 'El total supera el monto admitido para una liquidación.']);
                }
            }
            $gastos = 0; // PR 3 sumará aquí los gastos con foto de este día.
            $ahora = now()->startOfSecond();
            // La BD guarda segundos: un recierre inmediato debe quedar después de la reapertura.
            if ($cierre?->reabierto_en && $ahora->lte($cierre->reabierto_en)) {
                $ahora = $cierre->reabierto_en->copy()->addSecond();
            }
            $cierre ??= new SalidaRutaCierre(['salida_ruta_id' => $salida->id, 'fecha' => $fecha]);
            $cierre->fill(array_map(self::decimal(...), $totales) + [
                'gastos_total' => self::decimal($gastos),
                'a_entregar' => self::decimal($totales['efectivo_cobrado'] - $gastos),
                'entrega_dinero_personal_id' => $validados['entrega_dinero_personal_id'] ?? null,
                'cerrado_por' => $usuario->id, 'cerrado_en' => $ahora,
            ])->save();
            activity('salida_ruta_cierre')->performedOn($cierre)->causedBy($usuario)->log('cerró el día');

            return $cierre;
        });
    }

    public function reabrir(SalidaRuta $salida, string $fecha, string $motivo, User $usuario): void
    {
        Validator::make(['motivo' => $motivo], ['motivo' => ['required', 'string', 'min:5', 'max:300']])->validate();
        DB::transaction(function () use ($salida, $fecha, $motivo, $usuario) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $cierre = $salida->cierres()->whereDate('fecha', $fecha)->lockForUpdate()->firstOrFail();
            if (! $cierre->estaCerrado()) {
                throw ValidationException::withMessages(['cierre' => 'El día ya está reabierto.']);
            }
            $cierre->update(['reabierto_por' => $usuario->id, 'reabierto_en' => now()->max($cierre->cerrado_en), 'reabierto_motivo' => $motivo]);
            activity('salida_ruta_cierre')->performedOn($cierre)->causedBy($usuario)->withProperties(['motivo' => $motivo])->log('reabrió el día');
        });
    }

    /** Saldos informativos de salidas anteriores, incluso si salieron el mismo día. */
    public function saldosAnteriores(SalidaRuta $salida, array $salas): Collection
    {
        return SalidaRutaEntrega::with('dte')->entregadas()->whereIn('cliente_sucursal_id', $salas)
            ->whereHas('salida', fn ($q) => $q->where('fecha_inicio', '<', $salida->fecha_inicio->toDateString())
                ->orWhere(fn ($q) => $q->whereDate('fecha_inicio', $salida->fecha_inicio->toDateString())->where('id', '<', $salida->id)))
            ->whereColumn('monto_cobrado', '<', 'monto_esperado')->get()->groupBy('cliente_sucursal_id');
    }
}
