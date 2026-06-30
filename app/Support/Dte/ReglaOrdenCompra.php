<?php

namespace App\Support\Dte;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Enums\TipoDte;

/**
 * Regla ÚNICA de dominio: ¿este CCF exige número de orden de compra?
 *
 * Criterio (OR): la orden de compra es obligatoria si el CLIENTE la requiere
 * O si la SALA/SUCURSAL seleccionada la requiere explícitamente. Una sala NO
 * puede "apagar" la exigencia del cliente (si el cliente la pide, se pide).
 *
 * Esta clase es la fuente de verdad reutilizada por el FormRequest, los
 * servicios de borrador/generación/validación, el formulario y la
 * representación gráfica, para que la UI y el backend nunca discrepen.
 */
final class ReglaOrdenCompra
{
    /** ¿El par cliente/sala exige orden de compra? (independiente del tipo de DTE) */
    public static function requerida(?Cliente $cliente, ?ClienteSucursal $sucursal): bool
    {
        return (bool) ($cliente?->requiere_orden_compra)
            || ($sucursal?->requiere_orden_compra === true);
    }

    /** ¿El DTE exige orden de compra? Solo aplica al CCF. */
    public static function requeridaParaDte(Dte $dte): bool
    {
        if ($dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return false;
        }

        $dte->loadMissing(['cliente', 'clienteSucursal']);

        return self::requerida($dte->cliente, $dte->clienteSucursal);
    }
}
