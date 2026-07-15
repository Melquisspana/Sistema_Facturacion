<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoCliente;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Support\Dinero;

/**
 * Validación PREVIA e INTERNA de un DTE antes de (en el futuro) generar el JSON
 * oficial. NO valida contra el JSON Schema del MH: solo comprueba que el modelo
 * interno tenga los datos mínimos para poder mapearlo.
 *
 * Devuelve una lista de problemas legibles (vacía = listo para mapear).
 */
class ValidacionPreJsonService
{
    /**
     * @return array<int, string> Problemas encontrados (vacío = válido).
     */
    public function validar(Dte $dte): array
    {
        $dte->loadMissing(['establecimiento.empresa', 'puntoVenta', 'cliente', 'clienteSucursal', 'lineas', 'dteRelacionado']);

        $problemas = [];

        // Estado.
        if ($dte->estado !== EstadoDte::Generado) {
            $problemas[] = 'El documento debe estar en estado generado.';
        }

        // Líneas y totales.
        if ($dte->lineas->isEmpty()) {
            $problemas[] = 'El documento no tiene líneas.';
        }
        if ($dte->total_pagar === null) {
            $problemas[] = 'Los totales no están calculados.';
        }

        // Emisor (empresa + establecimiento + punto de venta).
        $this->validarEmisor($dte, $problemas);

        // Receptor según tipo.
        $this->validarReceptor($dte, $problemas);

        // Unidad de medida por línea (CAT-014).
        foreach ($dte->lineas as $linea) {
            if (blank($linea->unidad_codigo)) {
                $problemas[] = "La línea {$linea->numero_linea} no tiene unidad de medida (CAT-014).";
            }
        }

        // Orden de compra en CCF si el cliente/sucursal la requiere.
        if ($dte->tipo_dte === TipoDte::CreditoFiscal && $this->requiereOrdenCompra($dte) && blank($dte->numero_orden_compra)) {
            $problemas[] = 'El CCF requiere número de orden de compra.';
        }

        // REGLA OBLIGATORIA: toda Nota de Crédito (05) — devolución, avería, pronto pago,
        // cualquier tipo — debe estar vinculada a un CCF (03) ACEPTADO por Hacienda para poder
        // emitirse (generar JSON oficial, firmar, transmitir). Defensa en la capa de generación.
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            $rel = $dte->dte_relacionado_id ? $dte->dteRelacionado : null;
            if (! $rel) {
                $problemas[] = 'La nota de crédito debe estar vinculada a un CCF aceptado relacionado.';
            } elseif ($rel->tipo_dte !== TipoDte::CreditoFiscal) {
                $problemas[] = 'El documento relacionado de la nota de crédito debe ser un Comprobante de Crédito Fiscal (CCF).';
            } elseif (! $rel->aceptadoRealmentePorMh()) {
                // No basta estado local "aceptado": debe tener sello real + fecha_procesamiento_mh
                // (excluye mock/simulado y aceptaciones solo locales, cuyo codigoGeneracion no
                // existe en el MH → codigoMsg 014 "NO EXISTE UN REGISTRO CON ESTE DATO").
                $problemas[] = 'La Nota de Crédito debe relacionarse con un CCF aceptado realmente por Hacienda.';
            } elseif (Dinero::comparar((string) $dte->total_gravado, $this->saldoGravadoDisponible($rel, $dte)) > 0) {
                // El MH rechaza una NC cuyo monto gravado supere el del CCF relacionado
                // (codigoMsg 016). Aplica también a avería (productos manuales): el total
                // gravado debe caber en el saldo disponible del CCF.
                $problemas[] = 'La Nota de Crédito no puede superar el monto disponible del CCF relacionado.';
            }
        }

        return $problemas;
    }

    public function aprobado(Dte $dte): bool
    {
        return $this->validar($dte) === [];
    }

    /**
     * Saldo gravado disponible de un CCF para emitir notas de crédito:
     * total_gravado del CCF − Σ total_gravado de NC (05) REALMENTE ACEPTADAS por el MH
     * relacionadas a ese CCF (excluye la NC actual). Las NC rechazadas/invalidadas o solo
     * aceptadas localmente/mock NO consumen saldo.
     */
    private function saldoGravadoDisponible(Dte $ccf, Dte $ncActual): string
    {
        $yaAcreditado = (string) Dte::query()
            ->where('tipo_dte', TipoDte::NotaCredito->value)
            ->where('dte_relacionado_id', $ccf->id)
            ->aceptadoRealMh()
            ->when($ncActual->id !== null, fn ($q) => $q->where('id', '!=', $ncActual->id))
            ->sum('total_gravado');

        return Dinero::redondear(Dinero::restar((string) $ccf->total_gravado, $yaAcreditado), 2);
    }

    /**
     * @param  array<int, string>  $problemas
     */
    private function validarEmisor(Dte $dte, array &$problemas): void
    {
        if (! $dte->establecimiento_id || ! $dte->punto_venta_id) {
            $problemas[] = 'El documento debe tener establecimiento y punto de venta del emisor.';
        }

        $emisor = $dte->establecimiento?->empresa;
        if (! $emisor) {
            $problemas[] = 'No se encontró la empresa emisora.';

            return;
        }

        foreach (['nit' => 'NIT', 'nrc' => 'NRC', 'actividad_economica_id' => 'actividad económica', 'departamento_id' => 'departamento', 'municipio_id' => 'municipio'] as $campo => $etiqueta) {
            if (blank($emisor->{$campo})) {
                $problemas[] = "Falta {$etiqueta} del emisor.";
            }
        }

        if (blank($dte->establecimiento?->codigo)) {
            $problemas[] = 'El establecimiento del emisor no tiene código.';
        }
        if (blank($dte->establecimiento?->tipo_establecimiento)) {
            $problemas[] = 'El establecimiento del emisor no tiene tipo (CAT-009).';
        }
        if (blank($dte->puntoVenta?->codigo)) {
            $problemas[] = 'El punto de venta del emisor no tiene código.';
        }
    }

    /**
     * @param  array<int, string>  $problemas
     */
    private function validarReceptor(Dte $dte, array &$problemas): void
    {
        $cliente = $dte->cliente;

        switch ($dte->tipo_dte) {
            case TipoDte::CreditoFiscal:
            case TipoDte::NotaCredito:
                if (! $cliente) {
                    $problemas[] = 'El receptor es obligatorio para CCF / Nota de crédito.';
                    break;
                }
                if ($cliente->tipo_cliente !== TipoCliente::Contribuyente) {
                    $problemas[] = 'El receptor debe ser contribuyente.';
                }
                foreach (['num_documento' => 'documento', 'nrc' => 'NRC', 'actividad_economica_id' => 'actividad económica', 'departamento_id' => 'departamento', 'municipio_id' => 'municipio'] as $campo => $etiqueta) {
                    if (blank($cliente->{$campo})) {
                        $problemas[] = "Falta {$etiqueta} del receptor.";
                    }
                }
                break;

            case TipoDte::FacturaExportacion:
                if (! $cliente) {
                    $problemas[] = 'La factura de exportación requiere un receptor.';
                    break;
                }
                if ($cliente->tipo_cliente !== TipoCliente::Exportacion) {
                    $problemas[] = 'El receptor debe ser de exportación (extranjero).';
                }
                if (blank($cliente->pais_id)) {
                    $problemas[] = 'El receptor de exportación debe tener país.';
                }
                // El esquema oficial FEX exige descActividad del receptor (CAT-019): sin
                // actividad económica la generación fallaría contra el schema del MH.
                if (blank($cliente->actividad_economica_id)) {
                    $problemas[] = 'El receptor de exportación debe tener actividad económica (CAT-019).';
                }
                break;

            case TipoDte::Factura:
                // Factura 01: receptor opcional (consumidor final) por debajo del umbral
                // confirmado (config/dte.php 'factura_consumidor_final.receptor_obligatorio_desde',
                // hoy $25,000.00). Umbral ESTRICTO ("mayor que", no "mayor o igual"): total
                // exactamente igual al umbral NO exige receptor; solo lo exige si el total lo
                // SUPERA. Si la config quedara en null, este bloque no exige nada (compatibilidad
                // con el modo "sin umbral configurado").
                $umbral = config('dte.factura_consumidor_final.receptor_obligatorio_desde');
                if ($umbral !== null && ! $cliente && Dinero::comparar((string) $dte->total_pagar, (string) $umbral) > 0) {
                    $problemas[] = 'El receptor es obligatorio: el total supera el monto configurado para exigir identificación del consumidor final.';
                }
                break;

            default:
                break;
        }
    }

    private function requiereOrdenCompra(Dte $dte): bool
    {
        return \App\Support\Dte\ReglaOrdenCompra::requerida($dte->cliente, $dte->clienteSucursal);
    }
}
