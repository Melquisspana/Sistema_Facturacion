<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\ContingenciaEventoService;
use App\Support\HoraNegocio;

/**
 * Documentos emitidos en contingencia que siguen sin sello pasado el plazo de
 * Hacienda. El plazo lo calcula {@see ContingenciaEventoService::plazo()}, el mismo
 * que frena el envío.
 */
class ContingenciaVencida implements Detector
{
    public function __construct(private readonly ContingenciaEventoService $eventos) {}

    public function tipo(): string
    {
        return 'contingencia_vencida';
    }

    public function nombre(): string
    {
        return 'Contingencia con el plazo vencido';
    }

    public function aplica(): bool
    {
        return (bool) config('dte.contingencia.enabled', false);
    }

    public function revisar(): ?Hallazgo
    {
        $ahora = HoraNegocio::ahora();

        $plazos = Dte::query()
            ->where('estado', EstadoDte::Firmado->value)
            ->whereNotNull('contingencia_id')
            ->whereNull('sello_recepcion')
            ->with('contingencia')
            ->get(['id', 'contingencia_id'])
            ->map(fn (Dte $dte) => $dte->contingencia ? $this->eventos->plazo($dte->contingencia) : null)
            ->filter(fn ($plazo) => $plazo !== null && $ahora->gt($plazo));

        if ($plazos->isEmpty()) {
            return null;
        }

        $n = $plazos->count();

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento en contingencia pasó el plazo' : $n.' documentos en contingencia pasaron el plazo',
            detalle: 'Siguen sin sello de Hacienda después del plazo de 24 horas desde el cese de la contingencia.',
            queHacer: 'Abrí Contingencia y revisá los documentos pendientes. Pasado el plazo hay que pedir una prórroga al Ministerio de Hacienda.',
            conteo: $n,
            desde: $plazos->min(),
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
