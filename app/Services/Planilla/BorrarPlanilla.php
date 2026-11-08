<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Borrar una planilla con todo lo que creó, como si nunca se hubiera generado.
 *
 * SOLO LA ÚLTIMA. La que va arriba en la lista: la de período más reciente y, con el
 * mismo período, la que se hizo después. Para un pago individual, el último de su
 * persona ({@see posterior()}). Borrar una del medio dejaría un hueco en la
 * historia y descontaría de nuevo anticipos que la siguiente ya dio por recuperados.
 * Un borrador se borra siempre: no creó nada.
 *
 * NO SE BORRA SI SALIÓ DINERO O HAY PAPEL FIRMADO. Un pago registrado —aunque esté
 * revertido— y un documento subido son hechos; para esos casos sigue estando Anular.
 *
 * Lo que sí se borra: las líneas y sus conceptos, lo que descontaba de cada anticipo
 * (que vuelve a tener su saldo), lo que se le debía a cada persona y a los terceros en
 * Gastos con sus cuotas y sus eventos, y el ajuste interno si estaba anulada. Queda una
 * sola cosa: la constancia en la bitácora de quién la borró, sin importes.
 */
final class BorrarPlanilla
{
    /**
     * La planilla que va después de esta, si hay alguna.
     *
     * Un pago individual (vacaciones, indemnización) es de una persona: solo lo frena, o es frenado por, una
     * planilla posterior en la que esté alguien de los suyos. Entre quincenas y mensuales
     * todo sigue igual: cualquiera posterior cuenta.
     */
    public function posterior(Planilla $planilla): ?Planilla
    {
        $desde = $planilla->desde->toDateString();
        $personas = DB::table('planilla_detalles')->where('planilla_id', $planilla->id)->pluck('planilla_empleado_id');

        return Planilla::whereKeyNot($planilla->id)
            ->where(fn ($q) => $q->whereDate('desde', '>', $desde)
                ->orWhere(fn ($q) => $q->whereDate('desde', $desde)->where('id', '>', $planilla->id)))
            ->where(function ($q) use ($planilla, $personas) {
                $q->whereExists(fn ($s) => $s->from('planilla_detalles as d')
                    ->whereColumn('d.planilla_id', 'planillas.id')
                    ->whereIn('d.planilla_empleado_id', $personas));

                if (! $planilla->esIndividual()) {
                    $q->orWhereNotIn('clase', [VacacionesAnuales::CLASE, ...array_values(IndemnizacionBaja::CLASES)]);
                }
            })
            ->orderBy('desde')
            ->orderBy('id')
            ->first();
    }

    /**
     * Qué impide borrarla, en palabras. Vacío = se puede.
     *
     * @return array<int, string>
     */
    public function impedimentos(Planilla $planilla): array
    {
        $motivos = [];

        if (! $planilla->borrador() && ($siguiente = $this->posterior($planilla)) !== null) {
            $motivos[] = 'Solo se puede borrar la última planilla, y después de esta está '
                .($siguiente->esIndividual()
                    ? ($siguiente->clase === IndemnizacionBaja::CLASES['renuncia'] ? 'la ' : 'el ').lcfirst($siguiente->nombreDelPago())
                    : 'la '.$siguiente->periodo).'.';
        }

        $gastos = $this->gastos($planilla);
        $cuotas = DB::table('gastos_cuotas')->whereIn('gasto_id', $gastos)->pluck('id');

        if (DB::table('gastos_pago_aplicaciones')->whereIn('cuota_id', $cuotas)->exists()
            || DB::table('planilla_lotes')->where('planilla_id', $planilla->id)->exists()) {
            $motivos[] = 'Tiene pagos registrados, aunque estén revertidos. Para dejarla sin efecto, anulala.';
        }

        if (DB::table('planilla_documentos')->where('planilla_id', $planilla->id)->exists()
            || DB::table('gastos_adjuntos')->whereIn('gasto_id', $gastos)->exists()) {
            $motivos[] = 'Tiene documentos firmados subidos. Para dejarla sin efecto, anulala.';
        }

        // El ajuste que deja la anulación se borra con ella; uno hecho a mano en Gastos, no.
        if (DB::table('gastos_ajustes')->whereIn('cuota_id', $cuotas)
            ->where(fn ($q) => $q->where('tipo', '!=', 'correccion')->orWhere('motivo', 'not like', 'Planilla anulada:%'))
            ->exists()) {
            $motivos[] = 'Tiene ajustes hechos a mano en Gastos.';
        }

        return $motivos;
    }

    public function borrar(User $usuario, Planilla $planilla): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        DB::transaction(function () use ($usuario, $planilla) {
            $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();

            if (($motivos = $this->impedimentos($planilla)) !== []) {
                throw ValidationException::withMessages(['borrar' => $motivos]);
            }

            $detalles = DB::table('planilla_detalles')->where('planilla_id', $planilla->id)->pluck('id');
            $conceptos = DB::table('planilla_conceptos')->whereIn('planilla_detalle_id', $detalles)->pluck('id');
            $gastos = $this->gastos($planilla);
            $cuotas = DB::table('gastos_cuotas')->whereIn('gasto_id', $gastos)->pluck('id');

            // En este orden por las llaves foráneas: lo que apunta a algo, antes que ese algo.
            $borrado = [
                'anticipos_liberados' => DB::table('planilla_anticipo_aplicaciones')->whereIn('planilla_concepto_id', $conceptos)->delete(),
                'conceptos' => DB::table('planilla_conceptos')->whereIn('id', $conceptos)->delete(),
                'detalles' => DB::table('planilla_detalles')->whereIn('id', $detalles)->delete(),
                'obligaciones_terceros' => DB::table('planilla_obligaciones_terceros')->where('planilla_id', $planilla->id)->delete(),
                'eventos' => DB::table('gastos_eventos')->whereIn('gasto_id', $gastos)->delete()
                    + DB::table('gastos_eventos')->whereNull('gasto_id')
                        ->whereIn('accion', ['planilla_confirmada', 'planilla_anulada', 'planilla_documento_adjuntado'])
                        ->where('datos->planilla_id', $planilla->id)
                        ->delete(),
                'ajustes' => DB::table('gastos_ajustes')->whereIn('cuota_id', $cuotas)->delete(),
                'cuotas' => DB::table('gastos_cuotas')->whereIn('id', $cuotas)->delete(),
                'gastos' => DB::table('gastos')->whereIn('id', $gastos)->delete(),
            ];

            // Sin importes: la bitácora la ve quien no tiene `planilla.salarios`.
            activity('planilla')->performedOn($planilla)->causedBy($usuario)
                ->withProperties(['periodo' => $planilla->periodo, 'clase' => $planilla->clase, 'estado' => $planilla->estado] + $borrado)
                ->log('borró la planilla');

            $planilla->delete();
        });
    }

    /** @return Collection<int, int> los gastos que creó: uno por persona y uno por tercero */
    private function gastos(Planilla $planilla): Collection
    {
        return DB::table('planilla_detalles')->where('planilla_id', $planilla->id)->whereNotNull('gasto_id')->pluck('gasto_id')
            ->merge(DB::table('planilla_obligaciones_terceros')->where('planilla_id', $planilla->id)->whereNotNull('gasto_id')->pluck('gasto_id'))
            ->unique()->values();
    }
}
