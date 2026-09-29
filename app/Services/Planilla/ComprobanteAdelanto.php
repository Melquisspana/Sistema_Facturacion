<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Gastos\Dinero;
use Illuminate\Support\Collection;

/**
 * Los datos del comprobante de adelanto, y en particular su resta.
 *
 * ═══════ La resta, que es lo único delicado ═══════
 *
 *     saldo pendiente anterior  +  este adelanto  =  nuevo saldo por descontar
 *
 * «Pendiente anterior» NO es la suma de lo que se le ha adelantado alguna vez. Es lo
 * que queda vivo: importe menos lo ya recuperado, anticipo por anticipo. La diferencia
 * no es cosmética —es la diferencia entre un papel correcto y uno que le reclama a la
 * persona dinero que ya devolvió—.
 *
 * Ejemplo del caso que lo destapa: se le adelantaron 60, se le descontaron 20 en la
 * quincena pasada y hoy se le entregan 40.
 *
 *     por suma de adelantos (MAL):   60 + 40 = 100
 *     por saldo pendiente (BIEN):    40 + 40 =  80
 *
 * El anticipo del que ya se recuperó todo desaparece de la cuenta: su pendiente es
 * cero y no tiene por qué seguir apareciendo.
 *
 * ═══════ El dinero se registra UNA vez ═══════
 *
 * Este servicio no mueve dinero: lee. Entregar el adelanto crea un pago de Gastos
 * —el dinero que sale hoy— y la ficha del anticipo enlazada a ese pago. Cuando después
 * se descuenta en una quincena, el descuento CONSUME la ficha mediante una aplicación:
 * no sale dinero otra vez ni aparece un segundo gasto. Es el control de anticipos que
 * ya existía, con un papel para firmar delante.
 */
final class ComprobanteAdelanto
{
    /**
     * Lo que necesita el comprobante, todo en centavos.
     *
     * `$anticipo` es el que se acaba de entregar; el pendiente anterior se calcula
     * con TODOS LOS DEMÁS, porque este todavía no era saldo antes de existir.
     *
     * @return array{
     *     pendiente_anterior: int, este_adelanto: int, nuevo_saldo: int,
     *     vigentes: Collection<int, PlanillaAnticipo>
     * }
     */
    public function resta(PlanillaEmpleado $empleado, PlanillaAnticipo $anticipo): array
    {
        $otros = $this->vigentesDe($empleado)->reject(fn (PlanillaAnticipo $a) => $a->id === $anticipo->id);

        $anterior = $otros->sum(fn (PlanillaAnticipo $a) => $a->pendiente());
        $este = Dinero::centavos((string) $anticipo->importe);

        return [
            'pendiente_anterior' => $anterior,
            'este_adelanto' => $este,
            // El nuevo saldo se calcula con el pendiente REAL de este anticipo, no con
            // su importe: si alguien reimprime el comprobante después de haberlo
            // descontado, el papel no puede seguir diciendo que se deben los 40.
            'nuevo_saldo' => $anterior + $anticipo->pendiente(),
            'vigentes' => $otros,
        ];
    }

    /**
     * Saldo total por recuperar de una persona, en centavos. Es lo que la pantalla de
     * preparar muestra al lado de cada anticipo.
     */
    public function saldoPendiente(PlanillaEmpleado $empleado): int
    {
        return $this->vigentesDe($empleado)->sum(fn (PlanillaAnticipo $a) => $a->pendiente());
    }

    /**
     * Anticipos con algo por recuperar. Los ya saldados quedan fuera: su pendiente es
     * cero y sumarlos sería contar dinero que la persona ya devolvió.
     *
     * @return Collection<int, PlanillaAnticipo>
     */
    public function vigentesDe(PlanillaEmpleado $empleado)
    {
        return PlanillaAnticipo::where('planilla_empleado_id', $empleado->id)
            ->with('pago')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->filter(fn (PlanillaAnticipo $a) => $a->pendiente() > 0)
            ->values();
    }

    /** Número visible del comprobante. Se deriva del id: no hay un correlativo fiscal. */
    public function folio(PlanillaAnticipo $anticipo): string
    {
        return $anticipo->fecha->format('Y').'-ADEL-'.str_pad((string) $anticipo->id, 3, '0', STR_PAD_LEFT);
    }
}
