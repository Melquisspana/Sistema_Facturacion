<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\Salida\ApendiceDteData;
use App\DataTransferObjects\Dte\Salida\DocumentoRelacionadoDteData;
use App\DataTransferObjects\Dte\Salida\DteSalidaData;
use App\DataTransferObjects\Dte\Salida\EmisorDteData;
use App\DataTransferObjects\Dte\Salida\IdentificacionDteData;
use App\DataTransferObjects\Dte\Salida\LineaDteData;
use App\DataTransferObjects\Dte\Salida\ReceptorDteData;
use App\DataTransferObjects\Dte\Salida\ResumenDteData;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoMapeableException;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Support\Dte\NumeroALetras;

/**
 * Mapea un Dte (con sus relaciones) a la estructura INTERNA DteSalidaData.
 *
 * - PURO: no toca BD, no cambia estado, no asigna codigo_generacion/numero_control.
 *   Si esos campos están vacíos, se dejan en null (su asignación transaccional será
 *   responsabilidad futura de DteJsonService).
 * - Usa los SNAPSHOTS de dte_lineas (no los datos vivos del producto).
 * - No usa nombres del schema oficial: solo los DTOs internos.
 *
 * Antes de mapear corre ValidacionPreJsonService; si hay problemas lanza
 * DteNoMapeableException con la lista.
 */
class MapeadorDteSalida
{
    public function __construct(private readonly ValidacionPreJsonService $validacion) {}

    /**
     * @throws DteNoMapeableException
     */
    public function mapear(Dte $dte): DteSalidaData
    {
        $problemas = $this->validacion->validar($dte);
        if ($problemas !== []) {
            throw new DteNoMapeableException($problemas);
        }

        $dte->loadMissing([
            'establecimiento.empresa.actividadEconomica',
            'establecimiento.empresa.departamento',
            'establecimiento.empresa.municipio',
            'establecimiento.empresa.distrito',
            'puntoVenta',
            'cliente.actividadEconomica', 'cliente.pais', 'cliente.departamento', 'cliente.municipio', 'cliente.distrito',
            'clienteSucursal.departamento', 'clienteSucursal.municipio', 'clienteSucursal.distrito',
            'lineas',
            'dteRelacionado',
        ]);

        return new DteSalidaData(
            identificacion: $this->identificacion($dte),
            emisor: $this->emisor($dte),
            resumen: $this->resumen($dte),
            lineas: $this->lineas($dte),
            receptor: $this->receptor($dte),
            apendice: $this->apendice($dte),
            documentoRelacionado: $this->documentoRelacionado($dte),
        );
    }

    private function identificacion(Dte $dte): IdentificacionDteData
    {
        $version = (int) (config('dte.json.versiones')[$dte->tipo_dte->value] ?? 0);

        return new IdentificacionDteData(
            version: $version,
            ambiente: $dte->ambiente->value,
            tipoDte: $dte->tipo_dte->value,
            fechaEmision: $dte->fecha_emision?->format('Y-m-d') ?? '',
            horaEmision: (string) $dte->hora_emision,
            // Si NO existen todavía, se dejan en null (no se asignan aquí).
            numeroControl: $dte->numero_control,
            codigoGeneracion: $dte->codigo_generacion,
            tipoModelo: (int) ($dte->tipo_modelo ?? config('dte.json.tipo_modelo', 1)),
            tipoOperacion: (int) ($dte->tipo_operacion ?? config('dte.json.tipo_operacion', 1)),
            tipoContingencia: $dte->tipo_contingencia,
            motivoContingencia: $dte->motivo_contingencia,
            tipoMoneda: $dte->moneda ?: 'USD',
        );
    }

    private function emisor(Dte $dte): EmisorDteData
    {
        $est = $dte->establecimiento;
        $emp = $est?->empresa;

        return new EmisorDteData(
            nit: (string) ($emp?->nit ?? ''),
            nrc: (string) ($emp?->nrc ?? ''),
            nombre: (string) ($emp?->razon_social ?? ''),
            codigoEstablecimiento: (string) ($est?->codigo ?? ''),
            codigoPuntoVenta: (string) ($dte->puntoVenta?->codigo ?? ''),
            nombreComercial: $emp?->nombre_comercial,
            actividadEconomica: $emp?->actividadEconomica?->codigo,
            departamento: $emp?->departamento?->codigo,
            municipio: $emp?->municipio?->codigo,
            distrito: $emp?->distrito?->codigo,
            direccion: $emp?->direccion,
            telefono: $emp?->telefono,
            correo: $emp?->correo,
            tipoEstablecimiento: $est?->tipo_establecimiento?->value,
        );
    }

    private function receptor(Dte $dte): ?ReceptorDteData
    {
        $cliente = $dte->cliente;
        if (! $cliente) {
            return null; // Factura 01 a consumidor final
        }

        // Identidad fiscal: SIEMPRE del cliente (es el contribuyente con el NIT).
        // Ubicación: de la sala de entrega cuando el DTE tiene una seleccionada; si no,
        // del propio cliente (fallback). Se toma la sala como unidad completa para no
        // mezclar la dirección del cliente con el departamento/municipio de la sala.
        $sala = $dte->clienteSucursal;
        $ubicacion = $sala ?? $cliente;

        return new ReceptorDteData(
            tipoDocumento: $cliente->tipo_documento?->value,
            numDocumento: $cliente->num_documento,
            nrc: $cliente->nrc,
            nombre: $cliente->nombre,
            // Nombre comercial: el de la sala cuando existe; si no, el del cliente.
            nombreComercial: $sala?->nombre ?: $cliente->nombre_comercial,
            actividadEconomica: $cliente->actividadEconomica?->codigo,
            pais: $cliente->pais?->codigo,
            departamento: $ubicacion->departamento?->codigo,
            municipio: $ubicacion->municipio?->codigo,
            distrito: $ubicacion->distrito?->codigo,
            direccion: $ubicacion->direccion,
            telefono: $cliente->telefono,
            correo: $cliente->correo,
            tipoPersona: $cliente->tipo_persona?->value,
            sucursalNombre: $sala?->nombre,
        );
    }

    /**
     * @return array<int, LineaDteData>
     */
    private function lineas(Dte $dte): array
    {
        return $dte->lineas->map(fn (DteLinea $l) => new LineaDteData(
            numeroLinea: (int) $l->numero_linea,
            descripcion: (string) $l->descripcion,            // snapshot
            cantidad: (string) $l->cantidad,
            precioUnitario: (string) $l->precio_unitario,     // snapshot
            totalLinea: (string) $l->total_linea,
            tipoItem: $l->tipo_producto !== null ? (int) $l->tipo_producto->value : null, // CAT-011 snapshot
            codigo: $l->codigo,                                // snapshot
            codigoBarra: $l->codigo_barra,                     // snapshot
            unidadMedida: $l->unidad_codigo,                   // CAT-014 snapshot
            descuento: (string) $l->descuento_monto,
            ventaGravada: (string) $l->venta_gravada,
            ventaExenta: (string) $l->venta_exenta,
            ventaNoSujeta: (string) $l->venta_no_sujeta,
            ventaExportacion: (string) $l->venta_exportacion,
            iva: (string) $l->iva_linea,
            dteLineaOriginalId: $l->dte_linea_original_id,
        ))->all();
    }

    private function resumen(Dte $dte): ResumenDteData
    {
        return new ResumenDteData(
            totalGravado: (string) $dte->total_gravado,
            totalExento: (string) $dte->total_exento,
            totalNoSujeto: (string) $dte->total_no_sujeto,
            totalExportacion: (string) $dte->total_exportacion,
            descuentoGravado: (string) $dte->descuento_gravado,
            descuentoExento: (string) $dte->descuento_exento,
            descuentoNoSujeto: (string) $dte->descuento_no_sujeto,
            descuentoTotal: (string) $dte->total_descuento,
            iva: (string) $dte->iva,
            ivaRetenido: (string) $dte->iva_retenido,
            retencionRenta: (string) $dte->retencion_renta,
            totalAntesRetencion: (string) $dte->total_antes_retencion,
            montoTotalOperacion: (string) $dte->monto_total_operacion,
            totalPagar: (string) $dte->total_pagar,
            totalLetras: NumeroALetras::convertir($dte->total_pagar ?? 0),
            flete: $dte->flete !== null ? (string) $dte->flete : '0.00',
            seguro: $dte->seguro !== null ? (string) $dte->seguro : '0.00',
            condicionOperacion: $dte->condicion_operacion?->value,
            porcentajeDescuento: (string) ($dte->descuento_porcentaje_aplicado ?? '0.00'),
            formaPago: $dte->forma_pago,
        );
    }

    /**
     * @return array<int, ApendiceDteData>
     */
    private function apendice(Dte $dte): array
    {
        $apendice = [];

        if (filled($dte->numero_orden_compra)) {
            $etiqueta = $dte->cliente?->etiqueta_orden_compra ?: 'Orden de compra';
            $apendice[] = ApendiceDteData::ordenCompra($dte->numero_orden_compra, $etiqueta);
        }

        return $apendice;
    }

    /**
     * @return array<int, DocumentoRelacionadoDteData>
     */
    private function documentoRelacionado(Dte $dte): array
    {
        if ($dte->tipo_dte !== TipoDte::NotaCredito || ! $dte->dte_relacionado_id || ! $dte->dteRelacionado) {
            return [];
        }

        $original = $dte->dteRelacionado;

        // Preferir el código de generación / número de control OFICIAL del original.
        // NOTA: si el original aún no tiene codigo_generacion real (aceptado por el MH),
        // la NC NO puede emitirse ante Hacienda; aquí se usa el número interno solo como
        // referencia para la estructura preliminar.
        $numero = $original->codigo_generacion ?? $original->numero_control ?? $original->numero_interno ?? '';

        return [
            new DocumentoRelacionadoDteData(
                tipoDocumento: $original->tipo_dte->value,
                tipoGeneracion: 2, // 2 = electrónico
                numeroDocumento: (string) $numero,
                fechaEmision: $original->fecha_emision?->format('Y-m-d') ?? '',
            ),
        ];
    }
}
