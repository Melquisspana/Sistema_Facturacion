<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\LoteDteService;

/**
 * Documentos emitidos en contingencia sin sello a los que les quedan menos de
 * {@see LoteDteService::HORAS_AVISO_POR_VENCER} horas de plazo. Cuenta también los
 * que ya vencieron: así, al vencer, este aviso no se da por «normalizado» (de eso
 * avisa {@see ContingenciaVencida}). Se normaliza cuando llega el sello o cuando el
 * aviso recibido le da al documento las 72 h del lote.
 */
class ContingenciaPorVencer implements Detector
{
    public function __construct(private readonly LoteDteService $lotes) {}

    public function tipo(): string
    {
        return 'contingencia_por_vencer';
    }

    public function nombre(): string
    {
        return 'Contingencia por vencer';
    }

    public function aplica(): bool
    {
        return (bool) config('dte.contingencia.enabled', false);
    }

    public function revisar(): ?Hallazgo
    {
        $plazos = $this->lotes->plazosSinSello()->filter(fn ($plazo) => $this->lotes->porVencer($plazo));

        if ($plazos->isEmpty()) {
            return null;
        }

        $n = $plazos->count();
        $horas = LoteDteService::HORAS_AVISO_POR_VENCER;

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento en contingencia está por vencer' : $n.' documentos en contingencia están por vencer',
            detalle: 'Siguen sin sello de Hacienda y les quedan menos de '.$horas.' horas de plazo, o ya se pasaron.',
            queHacer: 'Abrí Contingencia: enviá el aviso de contingencia si falta, o revisá que los lotes salgan y queden aceptados. Pasado el plazo hay que pedir una prórroga al Ministerio de Hacienda.',
            conteo: $n,
            desde: $plazos->min()->copy()->subHours($horas),
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
