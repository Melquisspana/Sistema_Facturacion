<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Gasto;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Confirmar una planilla: convertirla en obligaciones reales.
 *
 * Es el ÚNICO acto que crea deuda en este módulo. Mientras la planilla es borrador no
 * existe ni una obligación, ni con los empleados ni con los terceros.
 *
 * ═══════════ Lo que crea, y por qué son dos cosas distintas ═══════════
 *
 *   POR EMPLEADO   una obligación por lo que se le paga (`a_pagar`). Es lo único que
 *                  esa persona va a cobrar.
 *   POR TERCERO    una obligación por tercero, sumando lo que se le entrega en TODA la
 *                  planilla. A la cooperativa se le hace un pago por la cuota de todos,
 *                  no uno por empleado.
 *
 * Las dos juntas, más los anticipos recuperados, suman el total de ingresos. Nunca se
 * crea una obligación por el total de ingresos: eso contaría el dinero dos veces.
 *
 * ═══════════ Por qué no se puede duplicar ═══════════
 *
 * Tres candados, todos en la base y ninguno una comprobación previa:
 *
 *  1. `planilla_detalles.gasto_id` es ÚNICO. Un detalle no puede apuntar a dos deudas.
 *  2. `planilla_obligaciones_terceros` es único por planilla y tercero.
 *  3. Las claves de los gastos se DERIVAN (UUIDv5) de la planilla y del detalle o del
 *     nombre del tercero, contra el índice único de `gastos.clave`.
 *
 * Y todo corre dentro de una transacción con la planilla bloqueada (`lockForUpdate`),
 * así que dos personas confirmando a la vez se serializan: la segunda encuentra la
 * planilla ya confirmada y devuelve lo mismo en vez de crear otra tanda.
 *
 * ═══════════ Los anticipos ═══════════
 *
 * Un descuento de anticipo no se limita a restar: crea una APLICACIÓN contra el
 * anticipo, que es lo que hace que no se pueda recuperar dos veces. Se comprueba aquí
 * —dentro de la transacción y con el anticipo bloqueado— que lo que se recupera no
 * supere lo que queda pendiente.
 *
 * NO CALCULA NADA LEGAL. Reparte importes que una persona escribió y revisó.
 */
final class ConfirmarPlanilla
{
    private const NS_EMPLEADO = 'c3a91f70-5d2b-4a18-9f3e-6b0c8d2e4a71';

    private const NS_TERCERO = 'd41d8cd9-8f00-4204-a980-0998ecf8427e';

    public function __construct(
        private PrepararPlanilla $preparar,
        private TotalesPlanilla $totales,
    ) {}

    public function confirmar(User $usuario, Planilla $planilla): Planilla
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        try {
            return DB::transaction(function () use ($usuario, $planilla) {
                $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();

                // Ya confirmada: se devuelve tal cual. Un reintento no puede crear una
                // segunda tanda de obligaciones.
                if ($planilla->confirmada()) {
                    return $planilla;
                }

                if (! $planilla->borrador()) {
                    throw ValidationException::withMessages([
                        'estado' => 'Esta planilla está '.mb_strtolower(Planilla::ESTADOS[$planilla->estado]).' y no se puede confirmar.',
                    ]);
                }

                $planilla->load('detalles.conceptos');

                if ($reparos = $this->preparar->reparosParaConfirmar($planilla)) {
                    throw ValidationException::withMessages(['reparos' => $reparos]);
                }

                $this->comprobarAnticipos($planilla);

                $creadas = 0;
                foreach ($planilla->detalles as $detalle) {
                    $creadas += $this->obligacionDelEmpleado($usuario, $planilla, $detalle) ? 1 : 0;
                    $this->aplicarAnticipos($detalle);
                }

                $terceros = $this->obligacionesDeTerceros($usuario, $planilla);

                $planilla->update([
                    'estado' => 'confirmada',
                    'confirmada_at' => now(),
                    'confirmada_por' => $usuario->id,
                ]);

                $t = $this->totales->dePlanilla($planilla->fresh()->load('detalles.conceptos'));

                DB::table('gastos_eventos')->insert([
                    'usuario_id' => $usuario->id,
                    'accion' => 'planilla_confirmada',
                    'datos' => json_encode([
                        'planilla_id' => $planilla->id,
                        'periodo' => $planilla->periodo,
                        'obligaciones_empleados' => $creadas,
                        'obligaciones_terceros' => $terceros,
                        'total_ingresos' => Dinero::decimal($t['total_ingresos']),
                        'a_pagar' => Dinero::decimal($t['a_pagar']),
                        'a_terceros' => Dinero::decimal($t['a_terceros']),
                        'anticipos_recuperados' => Dinero::decimal($t['anticipos']),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $planilla->fresh();
            });
        } catch (QueryException $e) {
            // Un índice único saltó: otro proceso confirmó esta misma planilla mientras
            // este lo intentaba. Se devuelve lo que quedó, que es lo correcto.
            if (str_contains(mb_strtolower($e->getMessage()), 'duplicate') || $e->getCode() === '23000') {
                return $planilla->fresh();
            }

            throw $e;
        }
    }

    /**
     * Que ningún anticipo se recupere por encima de lo que queda pendiente. Se hace
     * DENTRO de la transacción y bloqueando el anticipo: dos planillas confirmándose a
     * la vez no pueden repartirse el mismo saldo dos veces.
     */
    private function comprobarAnticipos(Planilla $planilla): void
    {
        $porAnticipo = [];

        foreach ($planilla->detalles as $detalle) {
            foreach ($detalle->conceptos as $concepto) {
                if ($concepto->destino !== 'anticipo' || $concepto->planilla_anticipo_id === null) {
                    continue;
                }

                $porAnticipo[$concepto->planilla_anticipo_id] ??= 0;
                $porAnticipo[$concepto->planilla_anticipo_id] += Dinero::centavos((string) $concepto->importe);
            }
        }

        foreach ($porAnticipo as $anticipoId => $recupera) {
            $anticipo = PlanillaAnticipo::whereKey($anticipoId)->lockForUpdate()->firstOrFail();

            if ($recupera > $anticipo->pendiente()) {
                throw ValidationException::withMessages([
                    'anticipos' => 'Del anticipo del '.$anticipo->fecha->format('d/m/Y').' quedan '
                        .Dinero::decimal($anticipo->pendiente()).' por recuperar y esta planilla descuenta '
                        .Dinero::decimal($recupera).'. No se puede recuperar dos veces el mismo anticipo.',
                ]);
            }
        }
    }

    /** Deja registrado cuánto de cada anticipo recuperó cada descuento. */
    private function aplicarAnticipos(PlanillaDetalle $detalle): void
    {
        foreach ($detalle->conceptos as $concepto) {
            if ($concepto->destino !== 'anticipo' || $concepto->planilla_anticipo_id === null) {
                continue;
            }

            // insertOrIgnore contra el índice único del concepto: si ya se aplicó, no
            // se aplica otra vez.
            DB::table('planilla_anticipo_aplicaciones')->insertOrIgnore([
                'planilla_anticipo_id' => $concepto->planilla_anticipo_id,
                'planilla_concepto_id' => $concepto->id,
                'importe' => $concepto->importe,
                'created_at' => now(),
            ]);
        }
    }

    /** La obligación con la persona por lo que se le paga. */
    private function obligacionDelEmpleado(User $usuario, Planilla $planilla, PlanillaDetalle $detalle): bool
    {
        if ($detalle->gasto_id !== null) {
            return false;
        }

        $aPagar = $this->totales->deDetalle($detalle)['a_pagar'];

        // Cero a pagar —todo se fue en descuentos— no crea obligación: no hay nada que
        // deber. Los descuentos a terceros de esa persona sí generan la suya.
        if ($aPagar <= 0) {
            return false;
        }

        $clave = Uuid::uuid5(self::NS_EMPLEADO, $planilla->clave.'|'.$detalle->id)->toString();

        $gasto = Gasto::firstOrCreate(['clave' => $clave], [
            'huella_peticion' => hash('sha256', $clave),
            'beneficiario' => $detalle->nombre_snapshot,
            'concepto' => 'Planilla '.$planilla->periodo.' · '.$detalle->nombre_snapshot,
            'categoria' => 'Planilla',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => $planilla->moneda,
            'importe' => Dinero::decimal($aPagar),
            'periodo_desde' => $planilla->desde,
            'periodo_hasta' => $planilla->hasta,
            'documentacion' => 'pendiente',
            'responsable_id' => $planilla->registrado_por,
            'registrado_por' => $usuario->id,
        ]);

        if ($gasto->cuotas()->count() === 0) {
            $gasto->cuotas()->create([
                'numero' => 1,
                'importe' => Dinero::decimal($aPagar),
                'vence' => ($planilla->fecha_pago ?? $planilla->hasta)->toDateString(),
            ]);
        }

        $detalle->update(['gasto_id' => $gasto->id]);

        return true;
    }

    /**
     * Una obligación por tercero, sumando toda la planilla.
     *
     * @return int cuántas se crearon
     */
    private function obligacionesDeTerceros(User $usuario, Planilla $planilla): int
    {
        $porTercero = [];

        foreach ($planilla->detalles as $detalle) {
            foreach ($detalle->conceptos as $concepto) {
                if (! $concepto->generaDeudaConTercero()) {
                    continue;
                }

                $nombre = trim($concepto->tercero);
                $porTercero[$nombre] ??= 0;
                $porTercero[$nombre] += Dinero::centavos((string) $concepto->importe);
            }
        }

        $creadas = 0;

        foreach ($porTercero as $nombre => $importe) {
            if ($importe <= 0) {
                continue;
            }

            $yaExiste = PlanillaObligacionTercero::where('planilla_id', $planilla->id)
                ->where('tercero', $nombre)->first();

            if ($yaExiste !== null) {
                continue;
            }

            $clave = Uuid::uuid5(self::NS_TERCERO, $planilla->clave.'|'.mb_strtolower($nombre))->toString();

            $gasto = Gasto::firstOrCreate(['clave' => $clave], [
                'huella_peticion' => hash('sha256', $clave),
                'beneficiario' => $nombre,
                'concepto' => 'Descuentos de planilla '.$planilla->periodo,
                'categoria' => 'Planilla',
                'ambito' => 'empresarial',
                'naturaleza' => 'operativo',
                'moneda' => $planilla->moneda,
                'importe' => Dinero::decimal($importe),
                'periodo_desde' => $planilla->desde,
                'periodo_hasta' => $planilla->hasta,
                'documentacion' => 'pendiente',
                'responsable_id' => $planilla->registrado_por,
                'registrado_por' => $usuario->id,
            ]);

            if ($gasto->cuotas()->count() === 0) {
                $gasto->cuotas()->create([
                    'numero' => 1,
                    'importe' => Dinero::decimal($importe),
                    'vence' => ($planilla->fecha_pago ?? $planilla->hasta)->toDateString(),
                ]);
            }

            PlanillaObligacionTercero::create([
                'planilla_id' => $planilla->id,
                'tercero' => $nombre,
                'importe' => Dinero::decimal($importe),
                'gasto_id' => $gasto->id,
                'created_at' => now(),
            ]);

            $creadas++;
        }

        return $creadas;
    }
}
