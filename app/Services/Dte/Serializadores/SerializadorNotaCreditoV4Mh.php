<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Services\Dte\Serializadores\Concerns\MapeaCatalogosMh;
use App\Services\Dte\Serializadores\NotaCreditoV4\CalculoImportesNcV4;
use App\Services\Dte\Serializadores\NotaCreditoV4\ImportesNcV4AjusteCcf;
use App\Support\Dinero;
use App\Support\Dte\CodigoGeneracion;

/**
 * NC Hacienda 2.0 (fe-nc-v4), detrás de DTE_NC_VERSION; producción sigue en v3.
 * Importes según Normativa de Cumplimiento 2.0, Anexo IV, pp.116 y 118-120:
 * el IVA de una NC que ajusta CCF se consolida en tributos, totalIva queda en
 * cero (ese campo corresponde a ajustes a CRE). El rechazo histórico con base
 * 1.00 e IVA 0.13 fue por enviarlo en totalIva; no demuestra que v4 sea inválida.
 * La aceptación debe comprobarse en pruebas del MH. La estructura y los
 * totales internos se conservan; las invariantes se exigen aquí a la estrategia.
 */
class SerializadorNotaCreditoV4Mh implements SerializadorMh
{
    use MapeaCatalogosMh;

    public function __construct(private readonly ?CalculoImportesNcV4 $calculo = null) {}

    public function serializar(DteSalidaData $d): array
    {
        $problemas = [];
        if ($d->identificacion->version !== 4) {
            $problemas[] = 'El serializador NC v4 requiere identificacion.version 4.';
        }
        if ($d->documentoRelacionado === []) {
            $problemas[] = 'La nota de crédito requiere un documento relacionado (CCF original).';
        }
        $relacionados = [];
        foreach ($d->documentoRelacionado as $rel) {
            if (! CodigoGeneracion::esValido((string) $rel->numeroDocumento)) {
                $problemas[] = 'El CCF relacionado no tiene código de generación oficial (genere primero el JSON del CCF original).';
            }
            $relacionados[] = [
                'tipoDocumento' => (string) $rel->tipoDocumento,
                'tipoGeneracion' => (int) $rel->tipoGeneracion,
                'numeroDocumento' => (string) $rel->numeroDocumento,
                'fechaEmision' => (string) $rel->fechaEmision,
            ];
        }
        $e = $d->emisor;
        $r = $d->receptor;
        $nit = preg_replace('/\D+/', '', (string) $r?->numDocumento) ?? '';
        if ($nit === '') {
            $problemas[] = 'La NC versión 4 requiere el NIT del receptor.';
        }
        if (blank($e->distrito) || blank($r?->distrito)) {
            $problemas[] = 'La NC versión 4 requiere distrito del emisor y del receptor (CAT-008).';
        }
        foreach ([$d->resumen->descuentoGravado, $d->resumen->descuentoExento, $d->resumen->descuentoNoSujeto] as $global) {
            if (Dinero::comparar($global, '0') > 0) {
                $problemas[] = 'La NC versión 4 todavía no admite descuento global: el resumen v4 no tiene descuGravada y falta confirmar con Hacienda cómo se prorratea. Emítala sin descuento global o en versión 3.';
                break;
            }
        }
        $cuerpo = [];
        foreach ($d->lineas as $l) {
            $uni = $this->uniMedida($l, $problemas);
            if ($l->tipoItem === null) {
                $problemas[] = "Línea {$l->numeroLinea}: falta tipo de ítem (CAT-011).";
            }
            $cuerpo[] = [
                'numItem' => $l->numeroLinea,
                'tipoItem' => (int) ($l->tipoItem ?? 0),
                'numeroDocumento' => $relacionados[0]['numeroDocumento'] ?? '',
                'cantidad' => (float) $l->cantidad,
                'codigo' => $l->codigo,
                'codTributo' => null,
                'uniMedida' => $uni,
                'descripcion' => $l->descripcion,
            ];
        }
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }
        $estrategia = $this->estrategia();
        $importes = $estrategia->lineas($d);
        $resumen = $estrategia->resumen($d, $importes);
        $this->validarImportes($d, $importes, $resumen);
        foreach ($cuerpo as $indice => &$linea) {
            $linea += $importes[$indice];
        }
        unset($linea);

        return [
            'identificacion' => $this->identificacionComun($d->identificacion) + ['fusion' => null],
            'documentoRelacionado' => $relacionados,
            // fe-nc-v4 no admite tipoEstablecimiento en el emisor (v3 sí).
            'emisor' => [
                'nit' => $e->nit,
                'nrc' => $e->nrc,
                'nombre' => $e->nombre,
                'codActividad' => (string) ($e->actividadEconomica ?? ''),
                'descActividad' => $this->descActividad($e->actividadEconomica),
                'nombreComercial' => $e->nombreComercial,
                'direccion' => $this->direccion($e->departamento, $e->municipio, $e->direccion, $e->distrito),
                'telefono' => $e->telefono,
                'correo' => $e->correo,
            ],
            'receptor' => [
                'tipoDocumento' => '36',
                'numDocumento' => $nit,
                'nrc' => $r->nrc,
                'nombre' => (string) $r->nombre,
                'codActividad' => (string) ($r->actividadEconomica ?? ''),
                'descActividad' => $this->descActividad($r->actividadEconomica),
                'nombreComercial' => $r->nombreComercial,
                'direccion' => $this->direccion($r->departamento, $r->municipio, $r->direccion ?: '—', $r->distrito),
                'telefono' => $r->telefono,
                'correo' => $r->correo,
            ],
            'ventaTercero' => null,
            'cuerpoDocumento' => $cuerpo,
            'resumen' => $resumen + [
                'totalLetras' => $d->resumen->totalLetras,
                'condicionOperacion' => (int) ($d->resumen->condicionOperacion ?? 1),
                'observaciones' => null,
                'codigoRetencionMH' => null,
            ],
            'apendice' => $this->apendiceComun($d->apendice),
        ];
    }

    private function estrategia(): CalculoImportesNcV4
    {
        $opcion = config('dte.json.nc_v4_calculo', 'ajuste_ccf');
        if ($opcion !== 'ajuste_ccf') {
            throw new DteNoSerializableException(['Estrategia de importes NC v4 desconocida: '.(string) $opcion.'.']);
        }

        return $this->calculo ?? app(ImportesNcV4AjusteCcf::class);
    }

    /** Las estrategias no pueden omitir el cuadre ni cambiar el total interno. */
    private function validarImportes(DteSalidaData $d, array $lineas, array $r): void
    {
        $problemas = [];
        if (count($lineas) !== count($d->lineas)) {
            $problemas[] = 'Los importes de NC v4 no corresponden a todas las líneas.';
        }
        foreach (['ventaGravada' => 'totalGravada', 'ventaExenta' => 'totalExenta', 'ventaNoSuj' => 'totalNoSuj', 'totalIva' => 'totalIva', 'ivaRete' => 'ivaRete', 'ivaPerci' => 'ivaPerci', 'montoDescu' => 'totalDescu'] as $campo => $total) {
            $suma = '0.00';
            foreach ($lineas as $linea) {
                if (! isset($linea[$campo])) {
                    $problemas[] = "Falta {$campo} en una línea de NC v4.";
                }
                $suma = Dinero::sumar($suma, $linea[$campo] ?? 0);
            }
            if (! isset($r[$total]) || Dinero::comparar($suma, $r[$total]) !== 0) {
                $problemas[] = "La suma de {$campo} de las líneas no cuadra con resumen.{$total} en NC v4.";
            }
        }
        foreach (['totalIva' => '0.00', 'ivaRete' => $d->resumen->ivaRetenido, 'totalPagar' => $d->resumen->totalPagar] as $campo => $interno) {
            if (! isset($r[$campo]) || Dinero::comparar($r[$campo], $interno) !== 0) {
                $problemas[] = "El {$campo} de NC v4 no cuadra con el importe interno del documento ({$interno}).";
            }
        }
        $ivaTributo = '0.00';
        $totalTributos = '0.00';
        $cantidadIva = 0;
        $codigosResumen = [];
        foreach ($r['tributos'] ?? [] as $tributo) {
            $codigo = (string) ($tributo['codigo'] ?? '');
            $codigosResumen[] = $codigo;
            $totalTributos = Dinero::sumar($totalTributos, $tributo['valor'] ?? 0);
            if ($codigo === '20') {
                $cantidadIva++;
                $ivaTributo = Dinero::sumar($ivaTributo, $tributo['valor'] ?? 0);
            }
        }
        $codigosLineas = [];
        foreach ($lineas as $linea) {
            foreach ($linea['tributos'] ?? [] as $codigo) {
                $codigosLineas[] = (string) $codigo;
            }
        }
        $codigosLineas = array_values(array_unique($codigosLineas));
        sort($codigosLineas);
        sort($codigosResumen);
        if ($codigosLineas !== $codigosResumen) {
            $problemas[] = 'Los tributos del resumen de NC v4 no consolidan los códigos de las líneas.';
        }
        if (($r['totalGravada'] ?? 0) > 0 && $cantidadIva !== 1) {
            $problemas[] = 'La NC v4 con ventas gravadas requiere el valor del tributo 20 en resumen.tributos.';
        }
        if (Dinero::comparar($ivaTributo, $d->resumen->iva) !== 0) {
            $problemas[] = 'El valor de resumen.tributos (tributo 20) de NC v4 no cuadra con el IVA interno del documento.';
        }
        $subtotal = Dinero::sumar(Dinero::sumar($r['totalNoSuj'] ?? 0, $r['totalExenta'] ?? 0), $r['totalGravada'] ?? 0);
        // Anexo IV, campos 135 y 150.3: descuento por ítem informativo;
        // las ventas ya son netas, y el IVA se suma una sola vez como tributo.
        $operacion = Dinero::sumar($subtotal, $totalTributos);
        $pagar = Dinero::sumar(Dinero::restar(Dinero::sumar($operacion, $r['ivaPerci'] ?? 0), $r['ivaRete'] ?? 0), $r['totalNoGravado'] ?? 0);
        foreach (['subTotalVentas' => $subtotal, 'montoTotalOperacion' => $operacion, 'totalPagar' => $pagar] as $campo => $esperado) {
            if (! isset($r[$campo]) || Dinero::comparar($r[$campo], Dinero::redondear($esperado)) !== 0) {
                $problemas[] = "El resumen.{$campo} no cumple la fórmula de cuadre de NC v4.";
            }
        }
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }
    }
}
