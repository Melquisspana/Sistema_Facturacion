<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Anular un gasto que no corresponde: se cargó dos veces, a otro proveedor, o el
 * período de una repetición no debía existir.
 *
 * ═══════ Anular no es borrar, ni revertir, ni ajustar ═══════
 *
 * - NO BORRA. El gasto queda con quién lo anuló, cuándo y por qué, abre su ficha y
 *   sale en la pestaña «Anulados». Lo que cambia es que desde `anulado_at` deja de
 *   ser deuda: su saldo cuenta cero en la ficha, el listado, el panel, los avisos y
 *   los resúmenes ({@see SaldosGastos}, {@see ConsultaGastos}).
 * - NO REVIERTE PAGOS. Si hay alguno vigente se frena y se nombra cuál revertir
 *   primero. Un pago es dinero declarado como salido; anular por encima lo dejaría
 *   pagando una deuda que ya no existe. Revertir es la otra operación, con su propio
 *   permiso y su propio motivo.
 * - NO ES UN AJUSTE. Antes, a falta de esto, se saldaba con un crédito «Corrección de
 *   importe»; eso mostraba «Saldada por ajuste» y dejaba el importe en los totales.
 *
 * ═══════ El pasado no se reescribe ═══════
 *
 * «Pendientes al 15 de septiembre» sigue mostrando un gasto anulado el 20: ese día se
 * debía. El corte lo decide `anulado_at` contra el fin del día, igual que una
 * reversión de pago.
 *
 * Lo que viene de otro módulo —hoy, Planilla— se anula allá: lo dice
 * {@see ProteccionDeGastos::motivoParaNoAnular()}.
 */
final class AnularGasto
{
    public function __construct(
        private AccesoGastos $acceso,
        private ProteccionDeGastos $proteccion,
    ) {}

    public function anular(User $usuario, Gasto $gasto, string $motivo): Gasto
    {
        abort_unless($usuario->activo && $usuario->can('gastos.administrar'), 403);

        $motivo = trim($motivo);
        Validator::make(['motivo' => $motivo], [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Explicá por qué se anula el gasto.',
            'motivo.min' => 'El motivo tiene que decir algo: dentro de un mes nadie sabrá si fue un error o una decisión.',
        ])->validate();

        return DB::transaction(function () use ($usuario, $gasto, $motivo) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

            // Mismo orden de bloqueo que pagos y ajustes —gasto y después cuotas— para
            // que un pago que entra a la vez no se cruce con la anulación.
            $gasto = Gasto::whereKey($gasto->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->acceso->ver($usuario, $gasto), 403);
            Cuota::where('gasto_id', $gasto->id)->orderBy('id')->lockForUpdate()->get();

            if ($gasto->anulado()) {
                return $gasto; // ya anulado: repetir no cambia el primer registro ni duplica el evento
            }

            if ($impedimento = $this->impedimento($gasto)) {
                throw ValidationException::withMessages(['anular' => $impedimento]);
            }

            $gasto->update([
                'anulado_at' => now(),
                'anulado_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            // El documento de Compras que originó esta deuda queda libre para registrarse
            // bien: el índice único vigila `deuda_unica`, y un anulado ya no debe nada. El
            // vínculo se conserva como `deuda` para que la ficha diga de dónde nació.
            DB::table('gastos_fuentes')->where('gasto_id', $gasto->id)->whereNotNull('deuda_unica')
                ->update(['deuda_unica' => null, 'updated_at' => now()]);

            // Los avisos que ya estaban en la bandeja hablan de una deuda que dejó de
            // existir: no piden nada más. Se marcan leídos, no se borran.
            if (Schema::hasTable('gastos_avisos')) {
                DB::table('gastos_avisos')->where('gasto_id', $gasto->id)->whereNull('leido_at')
                    ->update(['leido_at' => now()]);
            }

            // La bitácora del módulo (la ficha) y la general. Solo el motivo: el importe y
            // el destinatario ya están en el gasto, y la bitácora general la leen
            // personas que no necesariamente alcanzan los gastos personales.
            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'usuario_id' => $usuario->id,
                'accion' => 'gasto_anulado',
                'datos' => json_encode(['motivo' => $motivo], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            activity('gastos')->performedOn($gasto)->causedBy($usuario)
                ->withProperties(['motivo' => $motivo])
                ->log('anuló el gasto');

            return $gasto->fresh();
        });
    }

    /**
     * Por qué este gasto no se puede anular HOY, sin importar quién lo pida, o null.
     *
     * La ficha lo usa para decirlo antes de que alguien escriba un motivo en balde.
     */
    public function impedimento(Gasto $gasto): ?string
    {
        if ($motivo = $this->proteccion->motivoParaNoAnular($gasto->id)) {
            return $motivo;
        }

        $pagos = $this->pagosVigentes($gasto);

        if ($pagos->isEmpty()) {
            return null;
        }

        return 'No se puede anular: '.($pagos->count() === 1 ? 'tiene un pago vigente' : 'tiene pagos vigentes')
            .' ('.$pagos->map(fn (object $p) => 'Pago #'.$p->id.' del '.$p->fecha.', '
                .$gasto->moneda.' '.Dinero::mostrar($p->aplicado).' aplicado a este gasto')->join('; ').'). '
            .'Revertí primero '.($pagos->count() === 1 ? 'ese pago' : 'cada pago').', con su motivo, y después anulá el gasto.';
    }

    /**
     * Pagos vigentes que tocan este gasto, con lo aplicado a ÉL —no el total del pago,
     * que en un pago mixto delataría obligaciones de otro ámbito—.
     *
     * @return Collection<int, object{id: int, fecha: string, aplicado: int}>
     */
    public function pagosVigentes(Gasto $gasto): Collection
    {
        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->where('c.gasto_id', $gasto->id)
            ->whereNull('p.revertido_at')
            ->orderBy('p.fecha')->orderBy('p.id')
            ->get(['p.id', 'p.fecha', 'a.importe'])
            ->groupBy('id')
            ->map(fn (Collection $filas) => (object) [
                'id' => (int) $filas->first()->id,
                'fecha' => CarbonImmutable::parse($filas->first()->fecha)->format('d/m/Y'),
                'aplicado' => $filas->sum(fn ($f) => Dinero::centavos((string) $f->importe)),
            ])
            ->values();
    }
}
