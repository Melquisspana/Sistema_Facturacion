<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\LoteDteService;
use App\Support\HoraNegocio;

/**
 * Documentos emitidos en contingencia que siguen sin sello pasado su plazo: 24 h
 * desde el cese (o desde el rechazo del aviso) y, con el aviso recibido, 72 h para
 * el lote. El plazo de cada uno lo calcula {@see LoteDteService::plazoDocumento()},
 * el mismo que muestra la bandeja.
 */
class ContingenciaVencida implements Detector
{
    public function __construct(private readonly LoteDteService $lotes) {}

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
        $plazos = $this->lotes->plazosSinSello()->filter(fn ($plazo) => $plazo !== null && $ahora->gt($plazo));

        if ($plazos->isEmpty()) {
            return null;
        }

        $n = $plazos->count();

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento en contingencia pasó el plazo' : $n.' documentos en contingencia pasaron el plazo',
            detalle: 'Siguen sin sello de Hacienda después de su plazo: 24 horas desde el cese para enviar el aviso de contingencia, o 72 horas desde el aviso recibido para enviarlos en lote.',
            queHacer: 'Abrí Contingencia y revisá los documentos pendientes. Pasado el plazo hay que pedir una prórroga al Ministerio de Hacienda.',
            conteo: $n,
            desde: $plazos->min(),
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
