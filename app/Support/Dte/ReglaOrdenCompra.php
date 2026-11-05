<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Support\HoraNegocio;
use Carbon\CarbonInterface;

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
 *
 * También guarda la FORMA de la orden del cliente que la exige: 14 dígitos,
 * `AAMM` + `0` + 9 dígitos, con el año y el mes de la orden a no más de un mes
 * de la fecha del CCF. La forma solo AVISA (el usuario confirma y se genera
 * igual); la usan la emisión y la auditoría de Auditoría › Órdenes de compra.
 */
final class ReglaOrdenCompra
{
    public const LARGO = 14;

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

    /**
     * Qué no cuadra en la forma de la orden respecto de la fecha del CCF. Vacío si cuadra.
     * `$documento` nombra al que lleva la fecha en el aviso: el pedido de sala también la usa.
     *
     * @return list<string>
     */
    public static function problemasForma(?string $orden, CarbonInterface $fecha, string $documento = 'el CCF'): array
    {
        $orden = trim((string) $orden);
        if ($orden === '') {
            return ['No tiene orden de compra.'];
        }
        if (preg_match('/^\d+$/', $orden) !== 1) {
            return ['La orden de compra «'.$orden.'» tiene caracteres que no son números.'];
        }
        if (strlen($orden) !== self::LARGO) {
            return ['La orden de compra «'.$orden.'» tiene '.strlen($orden).' dígitos y deben ser '.self::LARGO.'.'];
        }

        $problemas = [];
        if ($orden[4] !== '0') {
            $problemas[] = 'El quinto dígito de la orden de compra «'.$orden.'» debería ser 0.';
        }

        $anio = 2000 + (int) substr($orden, 0, 2);
        $mes = (int) substr($orden, 2, 2);
        if ($mes < 1 || $mes > 12) {
            $problemas[] = 'La orden de compra «'.$orden.'» no empieza con un año y mes válidos (AAMM).';
        } elseif (abs(($anio * 12 + $mes) - ($fecha->year * 12 + $fecha->month)) > 1) {
            $problemas[] = 'La orden de compra «'.$orden.'» es de '.sprintf('%02d/%d', $mes, $anio)
                .' y '.$documento.' es del '.$fecha->format('d/m/Y').': debería ser del mismo mes, del anterior o del siguiente.';
        }

        return $problemas;
    }

    /**
     * Avisos que se confirman antes de generar el CCF: la forma de la orden y la sala
     * sin código. Vacío si el documento no exige orden de compra. La orden vacía no
     * avisa acá: la generación ya la frena por su cuenta.
     *
     * @return list<string>
     */
    public static function avisos(Dte $dte): array
    {
        if (! self::requeridaParaDte($dte)) {
            return [];
        }

        $avisos = filled($dte->numero_orden_compra)
            ? self::problemasForma($dte->numero_orden_compra, $dte->fecha_emision ?? HoraNegocio::hoy())
            : [];

        $sala = $dte->clienteSucursal;
        if ($sala !== null && blank($sala->codigo)) {
            $avisos[] = 'La sala «'.$sala->nombre.'» no tiene cargado su código de sala: cargalo en la ficha del cliente.';
        }

        return $avisos;
    }
}
