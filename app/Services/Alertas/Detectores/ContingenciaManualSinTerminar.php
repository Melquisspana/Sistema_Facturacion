<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\ContingenciaService;

/**
 * Contingencia activada a mano que sigue activa aunque Hacienda ya responde. No se
 * cierra sola (pudo activarse por otro motivo); mientras siga, todo sale provisional
 * y el plazo del aviso a Hacienda no empieza. Lo que Hacienda responde lo anota la
 * reconexión: {@see ContingenciaService::anotarHaciendaResponde()}.
 */
class ContingenciaManualSinTerminar implements Detector
{
    public function __construct(private readonly ContingenciaService $contingencias) {}

    public function tipo(): string
    {
        return 'contingencia_manual_sin_terminar';
    }

    public function nombre(): string
    {
        return 'Contingencia manual sin terminar';
    }

    public function aplica(): bool
    {
        return (bool) config('dte.contingencia.enabled', false);
    }

    public function revisar(): ?Hallazgo
    {
        $activa = $this->contingencias->activa();
        $desde = $activa ? $this->contingencias->haciendaRespondeDesde($activa) : null;
        if ($desde === null) {
            return null;
        }

        return new Hallazgo(
            titulo: 'Hacienda ya responde y la contingencia sigue activa',
            detalle: 'La contingencia se activó a mano y no se termina sola. Mientras siga activa, los documentos salen provisionales y no empieza el plazo para enviar el aviso a Hacienda.',
            queHacer: 'Si el motivo de la contingencia ya pasó, apretá «Terminar contingencia» y después enviá el aviso a Hacienda. Si sigue (por ejemplo, el firmador o la luz), dejala activa.',
            desde: $desde,
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
