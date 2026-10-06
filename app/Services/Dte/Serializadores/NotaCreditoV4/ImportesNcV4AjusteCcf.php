<?php

namespace App\Services\Dte\Serializadores\NotaCreditoV4;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Services\Dte\Serializadores\Concerns\MapeaCatalogosMh;
use App\Support\Dinero;

/**
 * NC que ajusta CCF: Normativa de Cumplimiento 2.0, Anexo IV.
 * - p.116, campos 99-101: totalIva por ítem es para ajustes a CRE (07);
 *   para CCF (03) va en cero. Retención por base gravada neta; sin percepción
 *   en este sistema. Se prorratea la retención interna con residuo exacto.
 * - p.118, campos 129 y 135: suma de ventas netas por ítem; totalDescu suma
 *   los descuentos por ítem y no se resta otra vez. No admite descuento global.
 * - pp.118-119, campos 136-138: IVA del documento en el tributo 20 consolidado,
 *   obligatorio cuando hay ventas gravadas, no en totalIva.
 * - p.119, campos 143-146: percepción, retención y totalIva suman los ítems;
 *   codigoRetencionMH null para CCF.
 * - p.120, campos 150.3 y 153.3: operación = ventas + tributos;
 *   totalPagar = operación + percepción − retención + cargos/abonos.
 *
 * El rechazo histórico «[resumen.totalIva] CALCULO INCORRECTO» ocurrió al
 * enviar totalIva 0.13 en una NC de CCF de base 1.00: ese IVA va en tributos;
 * el «IVA 13%» por ítem corresponde a ajustes a CRE. Se descartó la hipótesis
 * de IVA incluido. No modifica ni recalcula impuestos internos; la aceptación
 * de esta representación aún debe comprobarse en el ambiente de pruebas MH.
 */
class ImportesNcV4AjusteCcf implements CalculoImportesNcV4
{
    use MapeaCatalogosMh;

    public function lineas(DteSalidaData $d): array
    {
        foreach ($d->documentoRelacionado as $relacionado) {
            if ($relacionado->tipoDocumento !== '03') {
                throw new DteNoSerializableException(['la NC v4 de este sistema solo ajusta CCF (documento relacionado tipo 03).']);
            }
        }
        $base = '0.00';
        $ultima = null;
        foreach (array_values($d->lineas) as $indice => $l) {
            if (Dinero::comparar($l->ventaGravada, '0') > 0) {
                $base = Dinero::sumar($base, $l->ventaGravada);
                $ultima = $indice;
            }
        }
        $retenido = '0.00';
        $items = [];
        foreach (array_values($d->lineas) as $indice => $l) {
            $gravada = Dinero::comparar($l->ventaGravada, '0') > 0;
            $retencion = '0.00';
            if ($gravada) {
                // Bases ya netas del descuento de línea; el residuo queda en
                // la última gravada, aunque después haya líneas exentas.
                $retencion = $indice === $ultima
                    ? Dinero::restar($d->resumen->ivaRetenido, $retenido)
                    : Dinero::redondear(Dinero::dividir(Dinero::multiplicar($d->resumen->ivaRetenido, $l->ventaGravada), $base));
                $retenido = Dinero::sumar($retenido, $retencion);
            }
            $items[] = [
                'precioUni' => (float) $l->precioUnitario,
                'montoDescu' => (float) $l->descuento,
                'ventaNoSuj' => (float) $l->ventaNoSujeta,
                'ventaExenta' => (float) $l->ventaExenta,
                'ventaGravada' => (float) $l->ventaGravada,
                'tributos' => $gravada ? ['20'] : null,
                'totalIva' => 0.0,
                'ivaRete' => (float) Dinero::redondear($retencion),
                'noGravado' => 0.0,
                'ivaPerci' => 0.0,
            ];
        }

        return $items;
    }

    public function resumen(DteSalidaData $d, array $lineas): array
    {
        $totales = [];
        foreach (['ventaNoSuj' => 'totalNoSuj', 'ventaExenta' => 'totalExenta', 'ventaGravada' => 'totalGravada', 'montoDescu' => 'totalDescu', 'totalIva' => 'totalIva', 'ivaRete' => 'ivaRete', 'ivaPerci' => 'ivaPerci'] as $campo => $total) {
            $suma = '0.00';
            foreach ($lineas as $linea) {
                $suma = Dinero::sumar($suma, $linea[$campo]);
            }
            $totales[$total] = (float) Dinero::redondear($suma);
        }
        $subtotal = Dinero::redondear(Dinero::sumar(Dinero::sumar($totales['totalNoSuj'], $totales['totalExenta']), $totales['totalGravada']));
        $tributos = $totales['totalGravada'] > 0
            ? [['codigo' => '20', 'descripcion' => $this->descTributo('20'), 'valor' => (float) $d->resumen->iva]]
            : null;
        $operacion = Dinero::redondear(Dinero::sumar($subtotal, $tributos[0]['valor'] ?? 0));
        $pagar = Dinero::redondear(Dinero::restar(Dinero::sumar($operacion, $totales['ivaPerci']), $totales['ivaRete']));

        return $totales + [
            'subTotalVentas' => (float) $subtotal,
            'tributos' => $tributos,
            'totalNoGravado' => 0.0,
            'montoTotalOperacion' => (float) $operacion,
            'totalPagar' => (float) $pagar,
        ];
    }
}
