<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Support\HoraNegocio;
use App\Support\OrdenCompra;
use App\Support\Sala;
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
 * También guarda la FORMA de la orden del cliente que la exige: `AAMM` + la sala
 * (4 dígitos, empieza con 0) + un correlativo que crece con el tiempo, así que
 * vienen de 13, 14 y 15 dígitos (ej. 2611 0232 0010394). El año y el mes de la
 * orden van a no más de un mes de la fecha del CCF. La forma solo AVISA (el
 * usuario confirma y se genera igual); la usan la emisión y la auditoría de Auditoría › Órdenes de compra.
 */
final class ReglaOrdenCompra
{
    public const LARGO_MINIMO = 13;

    public const LARGO_MAXIMO = 15;

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
        if (strlen($orden) < self::LARGO_MINIMO || strlen($orden) > self::LARGO_MAXIMO) {
            return ['La orden de compra «'.$orden.'» tiene '.strlen($orden).' dígitos y deben ser de '
                .self::LARGO_MINIMO.' a '.self::LARGO_MAXIMO.'.'];
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
     * Lo que no cuadra en la orden de un CCF: la forma y, si la forma está bien, que la
     * orden traiga el código de otra sala. Solo compara con un código de sala de 4 dígitos
     * (el de Calleja); la sala sin código no se compara. La usan el aviso al generar y la
     * auditoría, para que nunca discrepen. La auditoría pasa `$posicionSala` ya leída
     * ({@see OrdenCompra::posicionSala()}) para no consultar los ajustes en cada CCF.
     *
     * @param  array{0: int, 1: int}|null  $posicionSala
     * @return list<string>
     */
    public static function problemasConSala(?string $orden, CarbonInterface $fecha, ?ClienteSucursal $sala, ?array $posicionSala = null): array
    {
        $problemas = self::problemasForma($orden, $fecha);
        if ($problemas !== [] || $sala === null) {
            return $problemas;
        }

        $deLaOrden = self::salaDistinta($orden, $sala, $posicionSala);
        if ($deLaOrden !== null) {
            $problemas[] = 'La orden de compra «'.trim((string) $orden).'» es de la sala '.$deLaOrden
                .' y la factura va para «'.$sala->nombre.'» ('.Sala::normalizar($sala->codigo).'): revisá la orden o la sala.';
        }

        return $problemas;
    }

    /**
     * Avisos que se confirman antes de generar el CCF: la forma de la orden, la sala
     * sin código y la orden que trae el código de otra sala. Vacío si el documento no
     * exige orden de compra. La orden vacía no avisa acá: la generación ya la frena por su cuenta.
     *
     * @return list<string>
     */
    public static function avisos(Dte $dte): array
    {
        if (! self::requeridaParaDte($dte)) {
            return [];
        }

        $sala = $dte->clienteSucursal;
        // Un borrador sale con la fecha del día en que se genera: se compara con la de hoy,
        // no con la de cuando se creó el borrador. Ya generado, con la suya.
        $fecha = $dte->esEditable() ? HoraNegocio::hoy() : ($dte->fecha_emision ?? HoraNegocio::hoy());
        $avisos = filled($dte->numero_orden_compra)
            ? self::problemasConSala($dte->numero_orden_compra, $fecha, $sala)
            : [];

        if ($sala !== null && blank($sala->codigo)) {
            $avisos[] = 'La sala «'.$sala->nombre.'» no tiene cargado su código de sala: cargalo en la ficha del cliente.';
        }

        return $avisos;
    }

    /**
     * La sala que trae la orden cuando NO es la de `$sala`; null si cuadra o no se puede
     * saber. Solo con un código de sala de 4 dígitos (el de Calleja): la sala sin código se
     * descarta antes de leer nada. `$posicionSala` es {@see OrdenCompra::posicionSala()} ya
     * leída, para quien recorre muchos documentos.
     *
     * @param  array{0: int, 1: int}|null  $posicionSala
     */
    public static function salaDistinta(?string $orden, ?ClienteSucursal $sala, ?array $posicionSala = null): ?string
    {
        $codigo = Sala::normalizar($sala?->codigo);
        if (preg_match('/^\d{4}$/', (string) $codigo) !== 1) {
            return null;
        }

        $deLaOrden = OrdenCompra::salaDesde($orden, $posicionSala);

        return $deLaOrden !== null && $deLaOrden !== $codigo ? $deLaOrden : null;
    }
}
