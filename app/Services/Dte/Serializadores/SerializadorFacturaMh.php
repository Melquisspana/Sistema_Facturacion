<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Services\Dte\Serializadores\Concerns\MapeaCatalogosMh;
use App\Support\Dinero;

/**
 * Serializa una Factura de consumidor final (01) al array oficial (fe-f-v2).
 *
 * Particularidades: el receptor puede ser null (consumidor final); el IVA va
 * INCLUIDO (ivaItem por línea, totalIva en resumen) y no se itemiza en tributos.
 * Usa datos ya calculados; no recalcula impuestos; no firma/transmite.
 *
 * MH exige que ventaGravada/totalGravada sea el importe BRUTO (con IVA
 * incluido) del ítem gravado, no la base neta separada del IVA que se usa
 * internamente para contabilidad (rechazo real código 003 "cálculo de total
 * por ítem erróneo"). Aquí se reconstruye el bruto sumando de vuelta el IVA
 * (neto + ivaLinea); ivaItem/totalIva se mantienen informativos y no se
 * suman de nuevo al total.
 */
class SerializadorFacturaMh implements SerializadorMh
{
    use MapeaCatalogosMh;

    public function serializar(DteSalidaData $d): array
    {
        $problemas = [];
        $cuerpo = $this->cuerpo($d, $problemas);
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }

        return [
            'identificacion' => $this->identificacionComun($d->identificacion),
            'documentoRelacionado' => null,
            'emisor' => $this->emisor($d),
            'receptor' => $this->receptor($d),
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => $this->resumen($d),
            'apendice' => $this->apendiceComun($d->apendice),
        ];
    }

    /** @return array<string, mixed> */
    private function emisor(DteSalidaData $d): array
    {
        $e = $d->emisor;

        return [
            'nit' => $e->nit,
            'nrc' => $e->nrc,
            'nombre' => $e->nombre,
            'codActividad' => (string) ($e->actividadEconomica ?? ''),
            'descActividad' => $this->descActividad($e->actividadEconomica),
            'nombreComercial' => $e->nombreComercial,
            'direccion' => $this->direccion($e->departamento, $e->municipio, $e->direccion, $e->distrito),
            'telefono' => $e->telefono,
            'correo' => $e->correo,
            'codEstable' => $e->codigoEstablecimiento ?: null,
            'codPuntoVenta' => $e->codigoPuntoVenta ?: null,
        ];
    }

    /** @return array<string, mixed>|null  Consumidor final → null. */
    private function receptor(DteSalidaData $d): ?array
    {
        $r = $d->receptor;
        if ($r === null) {
            return null;
        }

        return [
            'tipoDocumento' => $r->tipoDocumento,
            'numDocumento' => $r->numDocumento,
            'nrc' => $r->nrc,
            'nombre' => $r->nombre,
            'codActividad' => $r->actividadEconomica,
            'descActividad' => $r->actividadEconomica ? $this->descActividad($r->actividadEconomica) : null,
            'direccion' => $this->direccion($r->departamento, $r->municipio, $r->direccion ?: '—', $r->distrito),
            'telefono' => $r->telefono,
            'correo' => $r->correo,
        ];
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<int, array<string, mixed>>
     */
    private function cuerpo(DteSalidaData $d, array &$problemas): array
    {
        $items = [];
        foreach ($d->lineas as $l) {
            $uni = $this->uniMedida($l, $problemas);
            if ($l->tipoItem === null) {
                $problemas[] = "Línea {$l->numeroLinea}: falta tipo de ítem (CAT-011).";
            }

            // Bruto (con IVA incluido) esperado por MH para Factura; neto+iva = importe original.
            $ventaGravadaBruta = Dinero::redondear(Dinero::sumar($l->ventaGravada, $l->iva), 2);

            $items[] = [
                'numItem' => $l->numeroLinea,
                'tipoItem' => (int) ($l->tipoItem ?? 0),
                'numeroDocumento' => null,
                'cantidad' => (float) $l->cantidad,
                'codigo' => $l->codigo,
                'codTributo' => null,
                'uniMedida' => $uni,
                'descripcion' => $l->descripcion,
                'precioUni' => (float) $l->precioUnitario,
                'montoDescu' => (float) $l->descuento,
                'ventaNoSuj' => (float) $l->ventaNoSujeta,
                'ventaExenta' => (float) $l->ventaExenta,
                'ventaGravada' => (float) $ventaGravadaBruta,
                'tributos' => null, // IVA incluido: no se itemiza
                'psv' => 0.0,
                'noGravado' => 0.0,
                'ivaItem' => (float) $l->iva,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function resumen(DteSalidaData $d): array
    {
        $r = $d->resumen;
        // Bruto (con IVA incluido) esperado por MH; ver nota de clase.
        $totalGravada = (float) Dinero::redondear(Dinero::sumar($r->totalGravado, $r->iva), 2);
        $totalExenta = (float) $r->totalExento;
        $totalNoSuj = (float) $r->totalNoSujeto;
        $subTotalVentas = round($totalGravada + $totalExenta + $totalNoSuj, 2);
        $totalDescu = (float) $r->descuentoTotal;

        return [
            'totalNoSuj' => $totalNoSuj,
            'totalExenta' => $totalExenta,
            'totalGravada' => $totalGravada,
            'subTotalVentas' => $subTotalVentas,
            'descuNoSuj' => (float) $r->descuentoNoSujeto,
            'descuExenta' => (float) $r->descuentoExento,
            'descuGravada' => (float) $r->descuentoGravado,
            'porcentajeDescuento' => (float) $r->porcentajeDescuento,
            'totalDescu' => $totalDescu,
            'tributos' => null, // IVA incluido
            'subTotal' => round($subTotalVentas - $totalDescu, 2),
            'ivaRete' => (float) $r->ivaRetenido,
            'montoTotalOperacion' => (float) $r->montoTotalOperacion,
            'totalNoGravado' => 0.0,
            'totalPagar' => (float) $r->totalPagar,
            'totalLetras' => $r->totalLetras,
            'totalIva' => (float) $r->iva,
            'saldoFavor' => 0.0,
            'condicionOperacion' => (int) ($r->condicionOperacion ?? 1),
            'pagos' => null,
            'numPagoElectronico' => null,
            'observaciones' => null,
        ];
    }
}
