<?php

namespace App\Services\Dte\Serializadores;

use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoSerializableException;

/**
 * Devuelve el serializador oficial que corresponde a cada tipo de DTE.
 */
class SerializadorMhFactory
{
    public function para(TipoDte $tipo): SerializadorMh
    {
        return match ($tipo) {
            TipoDte::CreditoFiscal => app(SerializadorCcfMh::class),
            TipoDte::Factura => app(SerializadorFacturaMh::class),
            TipoDte::FacturaExportacion => app(SerializadorExportacionMh::class),
            TipoDte::NotaCredito => app(SerializadorNotaCreditoMh::class),
            default => throw new DteNoSerializableException(['Tipo '.$tipo->label().' no soportado para JSON oficial.']),
        };
    }
}
