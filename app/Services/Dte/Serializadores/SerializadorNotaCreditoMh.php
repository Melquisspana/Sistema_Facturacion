<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Services\Dte\Serializadores\Concerns\MapeaCatalogosMh;
use App\Support\Dte\CodigoGeneracion;

/**
 * Serializa una Nota de Crédito (05) al array oficial, estructura VERSIÓN 3 (fe-nc-v3),
 * idéntica a la NC que el MH aceptó en producción.
 *
 * POR QUÉ v3 Y NO v4: el MH RECHAZA la NC tipo 05 en estructura v4. Enviar el `resumen.totalIva`
 * (y el `totalIva` por línea) de la v4 produce `codigoMsg 020 · [resumen.totalIva] CALCULO
 * INCORRECTO`, sin importar el valor del IVA (se confirmó con el caso base 1.00 × 0.13 = 0.13).
 * La estructura aceptada por el MH para tipo 05 es la v3 (espejo del CCF + documentoRelacionado):
 * el IVA va SOLO en resumen.tributos. Referencia de oro y comparador en
 * resources/dte/ejemplos/05_nota_credito/. NO volver a v4 ni reintroducir totalIva.
 *
 * Particularidades de la v3 (a diferencia de la v4 que NO acepta el MH para tipo 05):
 *  - identificacion.version = 3, sin `fusion`.
 *  - El IVA va SOLO en resumen.tributos[0].valor (calculado sobre subTotal = totalGravada −
 *    descuGravada). NO hay `totalIva` por línea ni en el resumen.
 *  - Las líneas llevan ventaGravada BRUTO y montoDescu por línea; el descuento global va en
 *    resumen.descuGravada / totalDescu (NO se reduce el IVA por línea como en v4).
 *  - Línea SIN `totalIva`, `ivaPerci`, `ivaRete`, `noGravado`.
 *  - receptor con `nit` directo (no tipoDocumento/numDocumento); sin `distrito` en direccion.
 *  - resumen con `subTotal`, `descuGravada`, `ivaPerci1`, `ivaRete1`, `reteRenta`,
 *    `montoTotalOperacion`; SIN `totalIva`, `totalPagar`, `totalNoGravado`, `pagos`.
 *  - bloque `extension` con campos null.
 *  - documentoRelacionado OBLIGATORIO (CCF original); cada línea referencia su codigoGeneracion.
 *
 * Usa datos ya calculados; no recalcula totales; no firma.
 */
class SerializadorNotaCreditoMh implements SerializadorMh
{
    use MapeaCatalogosMh;

    public function serializar(DteSalidaData $d): array
    {
        $problemas = [];

        $relacionado = $this->documentoRelacionado($d, $problemas);
        $numeroOriginal = $relacionado[0]['numeroDocumento'] ?? '';
        $cuerpo = $this->cuerpo($d, $numeroOriginal, $problemas);

        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }

        return [
            'identificacion' => $this->identificacionComun($d->identificacion), // version 3, sin fusion
            'documentoRelacionado' => $relacionado,
            'emisor' => $this->emisor($d),
            'receptor' => $this->receptor($d, $problemas),
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => $this->resumen($d),
            'extension' => $this->extension(),
            'apendice' => $this->apendiceComun($d->apendice),
        ];
    }

    /** Solo dígitos (NIT/DUI sin guiones). */
    private function soloDigitos(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<int, array<string, mixed>>
     */
    private function documentoRelacionado(DteSalidaData $d, array &$problemas): array
    {
        if ($d->documentoRelacionado === []) {
            $problemas[] = 'La nota de crédito requiere un documento relacionado (CCF original).';

            return [];
        }

        $items = [];
        foreach ($d->documentoRelacionado as $rel) {
            $numero = (string) $rel->numeroDocumento;
            if (! CodigoGeneracion::esValido($numero)) {
                $problemas[] = 'El CCF relacionado no tiene código de generación oficial (genere primero el JSON del CCF original).';
            }
            $items[] = [
                'tipoDocumento' => (string) $rel->tipoDocumento,
                'tipoGeneracion' => (int) $rel->tipoGeneracion,
                'numeroDocumento' => $numero,
                'fechaEmision' => (string) $rel->fechaEmision,
            ];
        }

        return $items;
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
            'tipoEstablecimiento' => (string) ($e->tipoEstablecimiento ?? ''),
            'direccion' => [
                'departamento' => (string) ($e->departamento ?? ''),
                'municipio' => (string) ($e->municipio ?? ''),
                'complemento' => (string) ($e->direccion ?? ''),
            ],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
        ];
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<string, mixed>
     */
    private function receptor(DteSalidaData $d, array &$problemas): array
    {
        $r = $d->receptor;
        if ($r === null) {
            $problemas[] = 'La nota de crédito requiere receptor (contribuyente).';
        }

        return [
            // La NC v3 usa NIT directo (solo dígitos), igual que el CCF; sin tipoDocumento/numDocumento.
            'nit' => $this->soloDigitos($r?->numDocumento),
            'nrc' => $r?->nrc,
            'nombre' => (string) ($r?->nombre ?? ''),
            'codActividad' => (string) ($r?->actividadEconomica ?? ''),
            'descActividad' => $this->descActividad($r?->actividadEconomica),
            'nombreComercial' => $r?->nombreComercial,
            'direccion' => [
                'departamento' => (string) ($r?->departamento ?? ''),
                'municipio' => (string) ($r?->municipio ?? ''),
                'complemento' => (string) ($r?->direccion ?: '—'),
            ],
            'telefono' => $r?->telefono,
            'correo' => $r?->correo,
        ];
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<int, array<string, mixed>>
     */
    private function cuerpo(DteSalidaData $d, string $numeroOriginal, array &$problemas): array
    {
        $ivaCod = (string) config('dte.json.tributos.iva', '20');
        $items = [];
        foreach ($d->lineas as $l) {
            $uni = $this->uniMedida($l, $problemas);
            if ($l->tipoItem === null) {
                $problemas[] = "Línea {$l->numeroLinea}: falta tipo de ítem (CAT-011).";
            }
            $gravada = (float) $l->ventaGravada; // BRUTO (el descuento global va en el resumen)

            $items[] = [
                'numItem' => $l->numeroLinea,
                'tipoItem' => (int) ($l->tipoItem ?? 0),
                'numeroDocumento' => $numeroOriginal !== '' ? $numeroOriginal : '0',
                'cantidad' => (float) $l->cantidad,
                'codigo' => $l->codigo,
                'codTributo' => null,
                'uniMedida' => $uni,
                'descripcion' => $l->descripcion,
                'precioUni' => (float) $l->precioUnitario,
                'montoDescu' => (float) $l->descuento,
                'ventaNoSuj' => (float) $l->ventaNoSujeta,
                'ventaExenta' => (float) $l->ventaExenta,
                'ventaGravada' => $gravada,
                'tributos' => $gravada > 0 ? [$ivaCod] : null,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function resumen(DteSalidaData $d): array
    {
        $r = $d->resumen;

        $totalGravada = (float) $r->totalGravado;   // BRUTO
        $totalExenta = (float) $r->totalExento;
        $totalNoSuj = (float) $r->totalNoSujeto;
        $subTotalVentas = round($totalGravada + $totalExenta + $totalNoSuj, 2);

        $descuGravada = (float) $r->descuentoGravado;
        $descuExenta = (float) $r->descuentoExento;
        $descuNoSuj = (float) $r->descuentoNoSujeto;
        $totalDescu = round($descuGravada + $descuExenta + $descuNoSuj, 2);

        $subTotal = round($subTotalVentas - $totalDescu, 2);

        // IVA ya calculado por la calculadora sobre la base NETA (= subTotal). Va SOLO aquí.
        $iva = (float) $r->iva;
        $tributos = null;
        if ($iva > 0) {
            $ivaCod = (string) config('dte.json.tributos.iva', '20');
            $tributos = [['codigo' => $ivaCod, 'descripcion' => $this->descTributo($ivaCod), 'valor' => $iva]];
        }

        $montoTotalOperacion = round($subTotal + $iva, 2);

        return [
            'totalNoSuj' => $totalNoSuj,
            'totalExenta' => $totalExenta,
            'totalGravada' => $totalGravada,
            'subTotalVentas' => $subTotalVentas,
            'descuNoSuj' => $descuNoSuj,
            'descuExenta' => $descuExenta,
            'descuGravada' => $descuGravada,
            'totalDescu' => $totalDescu,
            'tributos' => $tributos,
            'subTotal' => $subTotal,
            'ivaPerci1' => 0.0,
            'ivaRete1' => 0.0,
            'reteRenta' => 0.0,
            'montoTotalOperacion' => $montoTotalOperacion,
            'totalLetras' => $r->totalLetras,
            'condicionOperacion' => (int) ($r->condicionOperacion ?? 1),
        ];
    }

    /** @return array<string, null> Bloque extension (sin datos de entrega/recepción). */
    private function extension(): array
    {
        return [
            'nombEntrega' => null,
            'docuEntrega' => null,
            'nombRecibe' => null,
            'docuRecibe' => null,
            'observaciones' => null,
        ];
    }
}
