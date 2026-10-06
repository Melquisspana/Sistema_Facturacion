<?php

namespace App\Services\Dte\Serializadores;

use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoSerializableException;

/**
 * Devuelve el serializador oficial que corresponde a cada tipo de DTE.
 */
class SerializadorMhFactory
{
    public function para(TipoDte $tipo, ?int $version = null): SerializadorMh
    {
        return match ($tipo) {
            TipoDte::CreditoFiscal => app(SerializadorCcfMh::class),
            TipoDte::Factura => app(SerializadorFacturaMh::class),
            TipoDte::FacturaExportacion => app(SerializadorExportacionMh::class),
            TipoDte::NotaCredito => match ($version) {
                3 => app(SerializadorNotaCreditoMh::class),
                4 => app(SerializadorNotaCreditoV4Mh::class),
                default => throw new DteNoSerializableException(['La nota de crédito requiere una versión explícita: solo se admiten 3 o 4 (recibida: '.($version ?? 'ninguna').').']),
            },
            default => throw new DteNoSerializableException(['Tipo '.$tipo->label().' no soportado para JSON oficial.']),
        };
    }
}
