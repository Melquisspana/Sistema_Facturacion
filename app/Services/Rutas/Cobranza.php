<?php

namespace App\Services\Rutas;

use App\Models\SalidaRutaDocumento;
use Illuminate\Support\Collection;

/**
 * El dinero de un conjunto de documentos: qué se facturó, qué se cobró, qué
 * descuenta una NC y cuánto falta.
 *
 * Vive aparte de {@see SeguimientoDocumentos} porque son dos preguntas distintas
 * —«¿en qué estado está?» contra «¿cuánta plata falta?»— y el dinero merece poder
 * probarse solo. Recibe la colección YA HIDRATADA: no consulta nada, solo suma lo
 * que cada documento ya sabe responder.
 *
 * ─────────────────────────── Las tres reglas que lo gobiernan ───────────────────────────
 *
 * 1. LO DESCONOCIDO NO ES CERO. Un documento sin monto no suma cero: queda fuera de
 *    los totales y se cuenta aparte. Un total que se traga los huecos parece exacto
 *    y no hay forma de notar que le falta la mitad.
 *
 * 2. UNA NC NUNCA RESTA DOS VECES. Mientras Calleja no la descuenta cuenta como
 *    «aceptada por aplicar»; en cuanto la descuenta pasa a «aplicada» y deja de
 *    contar en la primera. La decisión la toma el propio documento
 *    ({@see SalidaRutaDocumento::montoNcAceptadaPorAplicar()}), acá solo se suma.
 *
 * 3. EL SALDO NUNCA SE MUESTRA COMO UN SOLO NÚMERO. Siempre partido en:
 *
 *      · FUERA DE PPQ    -> todavía no se ingresó al proceso de cobro; el trabajo
 *                           pendiente es NUESTRO;
 *      · EN PPQ SIN PAGAR -> ya está presentado y Calleja no pagó; el trabajo
 *                           pendiente es IR A COBRAR.
 *
 *    Puede haber un total general, pero jamás en lugar de sus dos componentes:
 *    sumados, no se sabría cuál de los dos está creciendo.
 */
class Cobranza
{
    /** Tramos de antigüedad, en días desde la emisión. */
    public const TRAMOS = [
        '0-30' => [0, 30],
        '31-60' => [31, 60],
        '61-90' => [61, 90],
        '90+' => [91, null],
    ];

    /**
     * Las cifras de dinero del conjunto.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos  ya hidratados
     * @return array<string, float|int>
     */
    public function resumen(Collection $documentos): array
    {
        $suma = fn (callable $valor) => round(
            $documentos->reduce(fn (float $total, SalidaRutaDocumento $d) => $total + ($valor($d) ?? 0), 0.0),
            2,
        );

        $conSaldo = $documentos->filter(fn (SalidaRutaDocumento $d) => $d->tieneSaldo());

        // El desglose del saldo usa el MISMO estado que pinta la columna de cobro:
        // fuera de PPQ es no tener renglón; en PPQ sin pagar es tenerlo sin conciliar.
        $fueraPpq = $conSaldo->filter(fn (SalidaRutaDocumento $d) => ! $d->enPpq());
        $enPpq = $conSaldo->filter(fn (SalidaRutaDocumento $d) => $d->enPpq());

        $sumaSaldo = fn (Collection $c) => round(
            $c->reduce(fn (float $t, SalidaRutaDocumento $d) => $t + (float) $d->saldoPendiente(), 0.0),
            2,
        );

        return [
            'facturado' => $suma(fn (SalidaRutaDocumento $d) => $d->montoFacturado()),
            'cobrado' => $suma(fn (SalidaRutaDocumento $d) => $d->montoCobrado()),
            'nc_aplicada' => $suma(fn (SalidaRutaDocumento $d) => $d->montoNcAplicada()),
            'nc_aceptada' => $suma(fn (SalidaRutaDocumento $d) => $d->montoNcAceptadaPorAplicar()),

            'saldo' => $sumaSaldo($conSaldo),
            'saldo_fuera_ppq' => $sumaSaldo($fueraPpq),
            'saldo_en_ppq' => $sumaSaldo($enPpq),

            'documentos_con_saldo' => $conSaldo->count(),
            'documentos_fuera_ppq' => $fueraPpq->count(),
            'documentos_en_ppq' => $enPpq->count(),

            // Lo que quedó fuera de las sumas, declarado en vez de disimulado.
            'sin_monto' => $documentos->filter(fn (SalidaRutaDocumento $d) => $d->montoFacturado() === null)->count(),
            'saldo_desconocido' => $documentos->filter(fn (SalidaRutaDocumento $d) => ! $d->saldoConocido())->count(),
        ];
    }

    /**
     * Antigüedad del saldo, por tramos, contada desde la EMISIÓN del documento.
     *
     * Solo entran los documentos con saldo mayor a cero: lo ya cobrado no envejece.
     * Los que no tienen fecha —snapshots P001 incompletos— van a su propio grupo
     * «sin fecha» y NO se reparten entre los tramos: meterlos en 0-30 los haría
     * pasar por recientes sin ninguna prueba de que lo sean.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos  ya hidratados
     * @return array<string, array{documentos: int, monto: float}>
     */
    public function antiguedad(Collection $documentos): array
    {
        $conSaldo = $documentos->filter(fn (SalidaRutaDocumento $d) => $d->tieneSaldo());

        $tramos = [];
        foreach (self::TRAMOS as $nombre => [$desde, $hasta]) {
            $delTramo = $conSaldo->filter(function (SalidaRutaDocumento $d) use ($desde, $hasta) {
                $dias = $d->diasAntiguedad();

                return $dias !== null && $dias >= $desde && ($hasta === null || $dias <= $hasta);
            });

            $tramos[$nombre] = $this->celda($delTramo);
        }

        $tramos['sin_fecha'] = $this->celda(
            $conSaldo->filter(fn (SalidaRutaDocumento $d) => $d->diasAntiguedad() === null)
        );

        return $tramos;
    }

    /** @param Collection<int, SalidaRutaDocumento> $documentos */
    private function celda(Collection $documentos): array
    {
        return [
            'documentos' => $documentos->count(),
            'monto' => round($documentos->reduce(fn (float $t, SalidaRutaDocumento $d) => $t + (float) $d->saldoPendiente(), 0.0), 2),
        ];
    }
}
