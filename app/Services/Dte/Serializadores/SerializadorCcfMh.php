<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\CatalogoMh;

/**
 * Serializa un CCF (DteSalidaData) al ARRAY oficial del MH (schema fe-ccf-v4).
 *
 * - Usa datos YA CALCULADOS (no recalcula impuestos) y snapshots de líneas.
 * - Mapea unidad de medida (CAT-014), tipo de ítem (CAT-011) y tributo IVA (CAT-015)
 *   usando los catálogos cargados en catalogos_mh.
 * - NO firma, NO transmite, NO genera sello/PDF, NO asigna numeración oficial:
 *   si numeroControl/codigoGeneracion están vacíos, se dejan en null (no se inventan)
 *   y el validador/comando lo reportan.
 * - Si falta un mapeo imprescindible (unidad CAT-014 inválida, tipo de ítem ausente)
 *   lanza DteNoSerializableException con el detalle.
 */
class SerializadorCcfMh implements SerializadorMh
{
    /**
     * @return array<string, mixed>
     *
     * @throws DteNoSerializableException
     */
    public function serializar(DteSalidaData $d): array
    {
        $problemas = [];
        $cuerpo = $this->cuerpoDocumento($d, $problemas);
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }

        return [
            'identificacion' => $this->identificacion($d),
            'documentoRelacionado' => null,   // el CCF no lleva documento relacionado (sí la NC)
            'emisor' => $this->emisor($d),
            'receptor' => $this->receptor($d),
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => $this->resumen($d),
            'apendice' => $this->apendice($d),
        ];
    }

    /** @return array<string, mixed> */
    private function identificacion(DteSalidaData $d): array
    {
        $i = $d->identificacion;

        return [
            'version' => (int) ($i->version ?: 4),
            'ambiente' => (string) $i->ambiente,
            'tipoDte' => '03',
            'numeroControl' => $i->numeroControl,       // null si aún no tiene numeración oficial
            'codigoGeneracion' => $i->codigoGeneracion, // null si aún no tiene
            'tipoModelo' => (int) $i->tipoModelo,
            'tipoOperacion' => (int) $i->tipoOperacion,
            'tipoContingencia' => $i->tipoContingencia !== null ? (int) $i->tipoContingencia : null,
            'motivoContin' => $i->motivoContingencia,
            'fecEmi' => (string) $i->fechaEmision,
            'horEmi' => (string) $i->horaEmision,
            'tipoMoneda' => $i->tipoMoneda ?: 'USD',
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
                'departamento' => (string) ($e->departamento ?? ''),
                'municipio' => (string) ($e->municipio ?? ''),
                'distrito' => (string) ($e->distrito ?? ''),    // CAT-008 (desde empresa->distrito)
                'complemento' => (string) ($e->direccion ?? ''),
            ],
            'telefono' => $e->telefono,
            'correo' => $e->correo,
            'codEstable' => $e->codigoEstablecimiento ?: null,
            'codPuntoVenta' => $e->codigoPuntoVenta ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function receptor(DteSalidaData $d): array
    {
        $r = $d->receptor;

        return [
            // El MH exige el NIT del receptor SOLO en dígitos (sin guiones). El número
            // del cliente puede venir formateado ("0614-010101-001-1"); se normaliza.
            'nit' => $this->soloDigitos($r?->numDocumento),
            'nrc' => $r?->nrc,
            'nombre' => (string) ($r?->nombre ?? ''),
            'codActividad' => (string) ($r?->actividadEconomica ?? ''),
            'descActividad' => $this->descActividad($r?->actividadEconomica),
            'nombreComercial' => $r?->nombreComercial,
            'direccion' => [
                'departamento' => (string) ($r?->departamento ?? ''),
                'municipio' => (string) ($r?->municipio ?? ''),
                'distrito' => (string) ($r?->distrito ?? ''),   // CAT-008 (desde la sala de entrega)
                'complemento' => (string) ($r?->direccion ?? ''),
            ],
            'telefono' => $r?->telefono,
            'correo' => $r?->correo,
        ];
    }

    /**
     * @param  array<int, string>  $problemas
     * @return array<int, array<string, mixed>>
     */
    private function cuerpoDocumento(DteSalidaData $d, array &$problemas): array
    {
        $ivaCod = (string) config('dte.json.tributos.iva', '20');
        $items = [];

        foreach ($d->lineas as $l) {
            $uni = $this->unidadMedida($l, $problemas);
            if ($l->tipoItem === null) {
                $problemas[] = "Línea {$l->numeroLinea}: falta tipo de ítem (CAT-011).";
            }

            $gravada = (float) $l->ventaGravada;

            $items[] = [
                'numItem' => $l->numeroLinea,
                'tipoItem' => (int) ($l->tipoItem ?? 0),
                'numeroDocumento' => null,
                'codigo' => $l->codigo,
                'codTributo' => null,
                'descripcion' => $l->descripcion,
                'cantidad' => (float) $l->cantidad,
                'uniMedida' => $uni,
                'precioUni' => (float) $l->precioUnitario,
                'montoDescu' => (float) $l->descuento,
                'ventaNoSuj' => (float) $l->ventaNoSujeta,
                'ventaExenta' => (float) $l->ventaExenta,
                'ventaGravada' => $gravada,
                'tributos' => $gravada > 0 ? [$ivaCod] : null,
                'psv' => 0.0,
                'noGravado' => 0.0,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function resumen(DteSalidaData $d): array
    {
        $r = $d->resumen;

        $totalGravada = (float) $r->totalGravado;
        $totalExenta = (float) $r->totalExento;
        $totalNoSuj = (float) $r->totalNoSujeto;
        $subTotalVentas = round($totalGravada + $totalExenta + $totalNoSuj, 2);
        $totalDescu = (float) $r->descuentoTotal;
        $iva = (float) $r->iva;

        $tributos = null;
        if ($iva > 0) {
            $ivaCod = (string) config('dte.json.tributos.iva', '20');
            $tributos = [[
                'codigo' => $ivaCod,
                'descripcion' => $this->descTributo($ivaCod),
                'valor' => $iva,
            ]];
        }

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
            'tributos' => $tributos,
            'subTotal' => round($subTotalVentas - $totalDescu, 2),
            'ivaPerci' => 0.0,
            'ivaRete' => (float) $r->ivaRetenido,
            'montoTotalOperacion' => (float) $r->montoTotalOperacion,
            'totalNoGravado' => 0.0,
            'totalPagar' => (float) $r->totalPagar,
            'totalLetras' => $r->totalLetras,
            'saldoFavor' => 0.0,
            'condicionOperacion' => (int) ($r->condicionOperacion ?? 1),
            'pagos' => $this->pagos($r),
            'numPagoElectronico' => null,
            'observaciones' => null,
        ];
    }

    /**
     * Formas de pago (resumen.pagos). El esquema fe-ccf-v4 define `pagos` como
     * ["array","null"] con `minItems: 1`: un arreglo vacío `[]` es INVÁLIDO y el
     * servicio de recepción del MH RECHAZA `null` en el CCF. Por eso se emite SIEMPRE
     * un arreglo con al menos un pago: `montoPago` = total a pagar y `codigo` = forma
     * de pago (CAT-017) del documento o el default configurable.
     *
     * `plazo`/`periodo` dependen de la CONDICIÓN DE OPERACIÓN (CAT-016): el MH los
     * exige para operaciones A CRÉDITO (condicionOperacion=2) y NO los admite (van
     * null) en contado/otro. plazo = código CAT-018 (01=Días, 02=Meses, 03=Años);
     * periodo = cantidad (>0). Los valores de crédito salen de config (defaults
     * configurables, no inventados): dte.json.plazo_credito_default / periodo_credito_default.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pagos(ResumenDteData $r): array
    {
        $esCredito = (int) ($r->condicionOperacion ?? 1) === 2;

        return [[
            'codigo' => $r->formaPago ?: (string) config('dte.json.forma_pago_default', '01'),
            'montoPago' => (float) $r->totalPagar,
            'referencia' => null,
            'plazo' => $esCredito ? (string) config('dte.json.plazo_credito_default', '01') : null,
            'periodo' => $esCredito ? (int) config('dte.json.periodo_credito_default', 30) : null,
        ]];
    }

    /** Devuelve solo los dígitos de un valor (NIT del MH va sin guiones). */
    private function soloDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }

    /** @return array<int, array<string, mixed>>|null */
    private function apendice(DteSalidaData $d): ?array
    {
        if ($d->apendice === []) {
            return null;
        }

        $items = [];
        foreach ($d->apendice as $a) {
            $items[] = [
                'campo' => mb_substr((string) $a->campo, 0, 25),
                'etiqueta' => mb_substr((string) $a->etiqueta, 0, 50),
                'valor' => mb_substr((string) $a->valor, 0, 150),
            ];
        }

        return $items;
    }

    /**
     * Mapea/valida la unidad de medida (CAT-014). Debe ser un código numérico 1..99
     * presente en el catálogo. Si no lo es, agrega un problema y devuelve 0.
     *
     * @param  array<int, string>  $problemas
     */
    private function unidadMedida(LineaDteData $l, array &$problemas): int
    {
        $cod = (string) ($l->unidadMedida ?? '');
        if ($cod === '' || ! ctype_digit($cod)) {
            $problemas[] = "Línea {$l->numeroLinea}: unidad de medida CAT-014 inválida o ausente ('{$cod}').";

            return 0;
        }

        $n = (int) $cod;
        if ($n < 1 || $n > 99 || ! CatalogoMh::where('cat', '014')->where('codigo', $cod)->exists()) {
            $problemas[] = "Línea {$l->numeroLinea}: código de unidad de medida CAT-014 no reconocido ('{$cod}').";

            return 0;
        }

        return $n;
    }

    private function descActividad(?string $codigo): string
    {
        if (! $codigo) {
            return '';
        }

        return (string) (CatalogoMh::where('cat', '019')->where('codigo', $codigo)->value('valor') ?? '');
    }

    private function descTributo(string $codigo): string
    {
        return (string) (CatalogoMh::where('cat', '015')->where('codigo', $codigo)->value('valor')
            ?? 'Impuesto al Valor Agregado 13%');
    }
}
