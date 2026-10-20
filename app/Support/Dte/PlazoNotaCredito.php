<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Models\Dte;
use App\Support\HoraNegocio;

final class PlazoNotaCredito
{
    public static function fueraDePlazo(Dte $nc): ?array
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito || $nc->dte_relacionado_id === null) {
            return null;
        }
        $ccf = $nc->dteRelacionado()->first();
        if ($ccf?->fecha_emision === null) {
            return null;
        }
        $limite = $ccf->fecha_emision->copy()->addMonthsNoOverflow(3)->format('Y-m-d');

        return HoraNegocio::fechaHoy() > $limite
            ? ['fecha_ccf' => $ccf->fecha_emision->format('Y-m-d'), 'limite' => $limite]
            : null;
    }

    public static function aviso(array $plazo): string
    {
        return 'La NC supera el plazo de 3 meses desde la emisión del CCF ('.$plazo['fecha_ccf'].'; límite '.$plazo['limite'].'). Confirme e indique el motivo de la excepción legal para generar.';
    }
}
