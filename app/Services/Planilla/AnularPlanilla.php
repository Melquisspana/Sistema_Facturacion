<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Cuota;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Anular una planilla confirmada, dejando rastro de todo.
 *
 * ═══════ Qué NO hace, y por qué ═══════
 *
 * NO BORRA las obligaciones que creó. Borrarlas dejaría pagos apuntando al vacío y
 * haría desaparecer de los informes dinero que sí existió. En su lugar les aplica un
 * AJUSTE INTERNO por el saldo que queda, con el motivo de la anulación: la deuda se
 * extingue, la historia se conserva y cualquiera puede ver qué pasó y por qué.
 *
 * ═══════ Un ajuste interno NO es una nota de crédito ═══════
 *
 * Y la diferencia no es de vocabulario. Una nota de crédito es un DOCUMENTO TRIBUTARIO
 * que se le emite a un tercero y se transmite a Hacienda. Acá no hay tercero al que
 * emitirle nada: la empresa se está corrigiendo a sí misma un sueldo que calculó mal o
 * una planilla que no debió confirmarse.
 *
 * Por eso el ajuste se graba como `tipo => 'correccion'` y nunca como `'nota_credito'`.
 * Un sueldo anulado que apareciera en el sistema como nota de crédito ensuciaría la
 * lista de documentos fiscales con algo que jamás se emitió, y tarde o temprano alguien
 * lo cuadraría contra lo transmitido y no le saldría.
 *
 * Rastro, eso sí, el mismo: clave propia, importe exacto, motivo obligatorio, quién y
 * cuándo, y un evento en la bitácora de Gastos.
 *
 * NO SE PUEDE ANULAR SI YA SE PAGÓ ALGO. Un pago es una declaración de que el dinero
 * salió; anular por encima lo convertiría en un pago contra una deuda inexistente. Si
 * hay pagos, primero se revierten uno por uno —con su motivo— y después se anula. Es
 * más trabajo a propósito: deshacer dinero declarado no puede ser un clic.
 *
 * ═══════ Los anticipos se liberan ═══════
 *
 * Las aplicaciones contra anticipos se borran, así que lo que esta planilla iba a
 * recuperar vuelve a quedar pendiente. Si no se liberaran, ese dinero quedaría
 * recuperado sin que nadie lo haya descontado de verdad.
 */
final class AnularPlanilla
{
    public function __construct(
        private SaldosGastos $saldos,
        private EstadoPlanilla $estado,
    ) {}

    public function anular(User $usuario, Planilla $planilla, string $motivo): Planilla
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages([
                'motivo' => 'Explicá por qué se anula: sin motivo, dentro de un mes nadie sabrá si fue un error o una decisión.',
            ]);
        }

        return DB::transaction(function () use ($usuario, $planilla, $motivo) {
            $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();

            if ($planilla->estado === 'anulada') {
                return $planilla;
            }

            if (! $planilla->confirmada()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se anula una planilla confirmada. Un borrador se corrige o se deja como está.',
                ]);
            }

            $planilla->load('detalles.conceptos', 'detalles.gasto.cuotas');

            $this->exigirQueNoHayaPagos($planilla);

            $creditos = 0;
            foreach ($planilla->detalles as $detalle) {
                $creditos += $this->acreditar($usuario, $detalle->gasto, $motivo);
            }

            foreach (PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto.cuotas')->get() as $obligacion) {
                $creditos += $this->acreditar($usuario, $obligacion->gasto, $motivo);
            }

            // Los anticipos vuelven a quedar pendientes de recuperar.
            $liberados = DB::table('planilla_anticipo_aplicaciones')
                ->whereIn('planilla_concepto_id', DB::table('planilla_conceptos')
                    ->whereIn('planilla_detalle_id', $planilla->detalles->pluck('id'))
                    ->select('id'))
                ->delete();

            $planilla->update([
                'estado' => 'anulada',
                'anulada_at' => now(),
                'anulada_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            DB::table('gastos_eventos')->insert([
                'usuario_id' => $usuario->id,
                'accion' => 'planilla_anulada',
                'datos' => json_encode([
                    'planilla_id' => $planilla->id, 'periodo' => $planilla->periodo,
                    'motivo' => $motivo, 'obligaciones_acreditadas' => $creditos,
                    'anticipos_liberados' => $liberados,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $planilla->fresh();
        });
    }

    private function exigirQueNoHayaPagos(Planilla $planilla): void
    {
        $conPagos = [];

        foreach ($planilla->detalles as $detalle) {
            if ($this->estado->deDetalle($detalle)['pagado'] > 0) {
                $conPagos[] = $detalle->nombre_snapshot;
            }
        }

        foreach (PlanillaObligacionTercero::where('planilla_id', $planilla->id)->with('gasto.cuotas')->get() as $obligacion) {
            if ($this->estado->deTercero($obligacion)['pagado'] > 0) {
                $conPagos[] = $obligacion->tercero;
            }
        }

        if ($conPagos !== []) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede anular: ya hay pagos registrados ('.implode(', ', array_slice($conPagos, 0, 5))
                    .(count($conPagos) > 5 ? ' y otros' : '').'). '
                    .'Revertí primero esos pagos, cada uno con su motivo, y después anulá la planilla.',
            ]);
        }
    }

    /**
     * Extingue el saldo de una obligación con un ajuste interno por lo que queda.
     *
     * `direccion => 'credito'` describe hacia dónde mueve el saldo —lo baja—, no el tipo
     * de documento. El documento es `tipo => 'correccion'`: interno y no fiscal.
     *
     * @return int cuántas cuotas se acreditaron
     */
    private function acreditar(User $usuario, ?object $gasto, string $motivo): int
    {
        if ($gasto === null) {
            return 0;
        }

        $hechas = 0;

        foreach ($gasto->cuotas as $cuota) {
            $cuota = Cuota::whereKey($cuota->id)->lockForUpdate()->firstOrFail();
            $saldo = $this->saldos->pendienteCuota($cuota);

            if ($saldo <= 0) {
                continue;
            }

            // Exactamente el saldo: no puede dejar la cuota en negativo ni de más.
            DB::table('gastos_ajustes')->insert([
                'clave' => (string) Str::uuid(),
                'cuota_id' => $cuota->id,
                'direccion' => 'credito',
                'tipo' => 'correccion',
                'importe' => Dinero::decimal($saldo),
                'motivo' => 'Planilla anulada: '.$motivo,
                'registrado_por' => $usuario->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'usuario_id' => $usuario->id,
                'accion' => 'ajuste_registrado',
                'datos' => json_encode([
                    'direccion' => 'credito', 'tipo' => 'correccion',
                    'importe' => Dinero::decimal($saldo), 'cuota' => $cuota->numero,
                    'motivo' => 'Planilla anulada: '.$motivo,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $hechas++;
        }

        return $hechas;
    }
}
