<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\Enums\TipoItemExportacion;
use App\Enums\TipoPersona;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Services\Dte\Serializadores\Concerns\MapeaCatalogosMh;

/**
 * Serializa una Factura de Exportación (11) al array oficial (fe-fex-v3).
 *
 * Particularidades: IVA 0%; receptor EXTRANJERO con codPais/nombrePais (país
 * obligatorio); flete/seguro; incoterms desde CAT-031 si existe; emisor con
 * tipoItemExpor. Usa datos ya calculados; no recalcula; no firma/transmite.
 */
class SerializadorExportacionMh implements SerializadorMh
{
    use MapeaCatalogosMh;

    /**
     * Tributo fijo de la Factura de Exportación (CAT-015): IVA a tasa 0%. Fijo por
     * normativa (no depende de un cálculo variable por línea), por eso se declara
     * aquí en vez de en la calculadora/DTO compartidos con CCF/Factura/NC.
     */
    private const CODIGO_TRIBUTO_EXPORTACION = 'C3';

    private const DESCRIPCION_TRIBUTO_EXPORTACION = 'Impuesto al Valor Agregado (exportaciones) 0%';

    public function serializar(DteSalidaData $d): array
    {
        $problemas = [];
        $cuerpo = $this->cuerpo($d, $problemas);
        $receptor = $this->receptor($d, $problemas);
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }

        return [
            'identificacion' => $this->identificacionComun($d->identificacion),
            'documentoRelacionado' => null,
            'emisor' => $this->emisor($d),
            'receptor' => $receptor,
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'compraTercero' => null,
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
            'direccion' => [
                'departamento' => mb_substr((string) ($e->departamento ?? ''), 0, 2),
                'municipio' => mb_substr((string) ($e->municipio ?? ''), 0, 2),
                'distrito' => (string) ($e->distrito ?? ''),
                'complemento' => (string) ($e->direccion ?? ''),
            ],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
            'codEstable' => $e->codigoEstablecimiento ?: null,
            'codPuntoVenta' => $e->codigoPuntoVenta ?: null,
            'tipoItemExpor' => $e->tipoItemExpor ?? TipoItemExportacion::Bienes->value,
            'recintoFiscal' => $e->recintoFiscal,
            'tipoRegimen' => $e->tipoRegimen,
            'regimen' => $e->regimen,
        ];
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<string, mixed>
     */
    private function receptor(DteSalidaData $d, array &$problemas): array
    {
        $r = $d->receptor;
        $codPais = $r?->pais;
        if (blank($codPais)) {
            $problemas[] = 'La exportación requiere el país del receptor (CAT-020). Falta dato real.';
        }

        return [
            'nombre' => (string) ($r?->nombre ?? ''),
            'tipoDocumento' => (string) ($r?->tipoDocumento ?? ''),
            'numDocumento' => (string) ($r?->numDocumento ?? ''),
            'descActividad' => $r?->actividadEconomica ? $this->descActividad($r->actividadEconomica) : '',
            'nombreComercial' => $r?->nombreComercial,
            'codPais' => (string) ($codPais ?? ''),
            'nombrePais' => $this->nombrePais($codPais),
            'complemento' => (string) ($r?->direccion ?: '—'),
            // tipoPersona del receptor según el MH (1 natural / 2 jurídica). El DTO trae el
            // valor string del enum ('natural'/'juridica'); castearlo con (int) daría 0, por eso
            // se resuelve vía el enum. Por defecto natural (1) si no viene.
            'tipoPersona' => (TipoPersona::tryFrom((string) ($r?->tipoPersona ?? '')) ?? TipoPersona::Natural)->codigoMh(),
            'telefono' => $r?->telefono,
            'correo' => $r?->correo,
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
                'ventaGravada' => (float) $l->ventaExportacion, // la venta de exportación va aquí
                'tributos' => [self::CODIGO_TRIBUTO_EXPORTACION],
                'noGravado' => 0.0,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function resumen(DteSalidaData $d): array
    {
        $r = $d->resumen;
        $totalGravada = (float) $r->totalExportacion;
        $totalDescu = (float) $r->descuentoTotal;

        return [
            'totalGravada' => $totalGravada,
            'descuGravada' => $totalDescu,
            'porcentajeDescuento' => (float) $r->porcentajeDescuento,
            'totalDescu' => $totalDescu,
            'seguro' => (float) $r->seguro,
            'flete' => (float) $r->flete,
            'tributos' => [[
                'codigo' => self::CODIGO_TRIBUTO_EXPORTACION,
                'descripcion' => self::DESCRIPCION_TRIBUTO_EXPORTACION,
                'valor' => 0.00,
            ]],
            'montoTotalOperacion' => (float) $r->montoTotalOperacion,
            'totalNoGravado' => 0.0,
            'totalNoOnerosas' => 0.0,
            'totalPagar' => (float) $r->totalPagar,
            'totalLetras' => $r->totalLetras,
            'saldoFavor' => 0.0,
            'condicionOperacion' => (int) ($r->condicionOperacion ?? 1),
            'pagos' => null,
            'codIncoterms' => $r->codIncoterms,   // CAT-031
            'descIncoterms' => $r->descIncoterms,
            'numPagoElectronico' => null,
            'observaciones' => null,
        ];
    }
}
