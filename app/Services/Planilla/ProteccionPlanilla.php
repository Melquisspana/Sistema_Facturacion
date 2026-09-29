<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planilla protegiendo sus obligaciones dentro de Gastos.
 *
 * ═════════ Por qué hace falta ═════════
 *
 * Las obligaciones que genera una planilla SON gastos, y tienen que serlo: así el
 * dinero vive en un solo sitio y los pagos, saldos e informes son los de siempre. Pero
 * el beneficiario es una persona y el importe es su sueldo.
 *
 * Sin esto, quien tuviera `gastos.ver` —sin ningún permiso laboral— abría el listado
 * de Gastos y leía cuánto cobra cada quien; y quien tuviera `gastos.pagos.registrar`
 * podía pagarlas o revertirlas. Las pantallas de Planilla estaban protegidas y la
 * puerta de atrás no. Cerrar pantallas nunca fue cerrar un dato.
 *
 * ═════════ Y los anticipos ═════════
 *
 * Un pago que está registrado como anticipo y YA fue descontado en una planilla no se
 * puede revertir sin más: revertirlo diría que ese dinero nunca salió, mientras la
 * planilla ya se lo descontó a la persona. Quedaría cobrada de menos y sin rastro de
 * por qué. Primero hay que resolver las aplicaciones —anulando la planilla que lo
 * descontó— y después revertir.
 *
 * ═════════ Si Planilla no está instalada ═════════
 *
 * No protege nada, y es lo correcto: Gastos funciona exactamente igual que antes. Se
 * comprueba con `Schema::hasTable` una sola vez por petición; es lo único que se
 * memoiza acá, porque es lo único que no puede cambiar a mitad de una petición.
 */
final class ProteccionPlanilla implements ProteccionDeGastos
{
    private ?bool $instalada = null;

    public function ocultosPara(User $usuario): ?Builder
    {
        // Quien tiene el permiso de salarios ve estas obligaciones como cualquier otra,
        // y su consulta no lleva ni una cláusula de más.
        return $usuario->can('planilla.salarios') ? null : $this->protegidos();
    }

    public function protegidos(): ?Builder
    {
        if (! $this->instalada()) {
            return null;
        }

        // Las dos tablas que apuntan a un gasto: lo que se le debe a cada persona y lo
        // que se le desvió a un tercero. Las dos son importes de planilla.
        return DB::table('planilla_detalles')
            ->select('gasto_id')
            ->whereNotNull('gasto_id')
            ->union(
                DB::table('planilla_obligaciones_terceros')
                    ->select('gasto_id')
                    ->whereNotNull('gasto_id')
            );
    }

    public function puedeVer(User $usuario, int $gastoId): bool
    {
        if (! $this->instalada() || $usuario->can('planilla.salarios')) {
            return true;
        }

        // Los importes de planilla son confidenciales, y este gasto sería uno de ellos.
        return ! $this->esDePlanilla($gastoId);
    }

    public function motivoParaNoPagar(User $usuario, array $gastoIds): ?string
    {
        if (! $this->instalada() || $gastoIds === [] || $usuario->can('planilla.pagar')) {
            return null;
        }

        $hayDePlanilla = DB::table('planilla_detalles')->whereIn('gasto_id', $gastoIds)->exists()
            || DB::table('planilla_obligaciones_terceros')->whereIn('gasto_id', $gastoIds)->exists();

        if (! $hayDePlanilla) {
            return null;
        }

        return 'Esta obligación viene de una planilla. Pagarla exige el permiso de pagos de planilla, '
            .'no alcanza con el de pagos de gastos.';
    }

    public function motivoParaNoRevertir(User $usuario, Pago $pago): ?string
    {
        if (! $this->instalada() || $usuario->can('planilla.pagar')) {
            return null;
        }

        // Revertir un pago de planilla exige el permiso laboral, además del de corregir
        // pagos que Gastos ya pide por su cuenta.
        $gastosDelPago = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->where('a.pago_id', $pago->id)
            ->pluck('c.gasto_id')
            ->all();

        if ($this->motivoParaNoPagar($usuario, $gastosDelPago) === null) {
            return null;
        }

        return 'Este pago corresponde a una obligación de planilla. Revertirlo exige el permiso de pagos '
            .'de planilla, no alcanza con el de corregir pagos de gastos.';
    }

    public function impedimentoParaRevertir(Pago $pago): ?string
    {
        if (! $this->instalada()) {
            return null;
        }

        // Un pago que es un ANTICIPO ya descontado no se revierte sin resolver antes sus
        // aplicaciones. No es cuestión de permisos —a quien los tenga todos le pasaría
        // lo mismo—, así que lo que hace falta es decirle qué desatar primero.
        $anticipo = DB::table('planilla_anticipos')->where('pago_id', $pago->id)->first();

        if ($anticipo === null) {
            return null;
        }

        $aplicaciones = DB::table('planilla_anticipo_aplicaciones')
            ->where('planilla_anticipo_id', $anticipo->id)
            ->count();

        if ($aplicaciones === 0) {
            return null;
        }

        return 'Este pago está registrado como un anticipo que ya se descontó en una planilla '
            .'('.$aplicaciones.' aplicación'.($aplicaciones === 1 ? '' : 'es').'). Revertirlo dejaría a la '
            .'persona con un descuento sin contrapartida. Anulá primero la planilla que lo descontó, y '
            .'después revertí el pago.';
    }

    private function esDePlanilla(int $gastoId): bool
    {
        return DB::table('planilla_detalles')->where('gasto_id', $gastoId)->exists()
            || DB::table('planilla_obligaciones_terceros')->where('gasto_id', $gastoId)->exists();
    }

    private function instalada(): bool
    {
        return $this->instalada ??= Schema::hasTable('planilla_detalles')
            && Schema::hasTable('planilla_obligaciones_terceros')
            && Schema::hasTable('planilla_anticipos');
    }
}
