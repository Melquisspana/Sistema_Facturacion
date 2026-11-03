<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use Illuminate\Support\Carbon;

/**
 * DTE rechazados por Hacienda que nadie resolvió: siguen sin archivar en el
 * ambiente de esta instalación.
 */
class DteRechazados implements Detector
{
    public function tipo(): string
    {
        return 'dte_rechazados';
    }

    public function nombre(): string
    {
        return 'DTE rechazados por Hacienda';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $rechazados = Dte::query()->ambienteOperativoActual()->noArchivados()
            ->where('estado', EstadoDte::Rechazado->value);

        $n = (clone $rechazados)->count();

        if ($n === 0) {
            return null;
        }

        return new Hallazgo(
            titulo: $n === 1 ? 'Hacienda rechazó 1 documento' : 'Hacienda rechazó '.$n.' documentos',
            detalle: $n === 1 ? 'Hay 1 DTE rechazado sin resolver.' : 'Hay '.$n.' DTE rechazados sin resolver.',
            queHacer: 'Abrí Facturación, filtrá por «Rechazado», leé el motivo de Hacienda y corregí o archivá cada documento.',
            conteo: $n,
            desde: Carbon::parse($rechazados->min('updated_at')),
            enlace: 'facturacion.index',
        );
    }
}
