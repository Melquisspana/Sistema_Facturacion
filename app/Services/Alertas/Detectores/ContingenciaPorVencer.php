<?php

namespace App\Services\Alertas\Detectores;

use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\LoteDteService;
use App\Support\HoraNegocio;

/**
 * Documentos emitidos en contingencia sin sello a los que les quedan menos de
 * {@see LoteDteService::HORAS_AVISO_POR_VENCER} horas de plazo. Los que ya vencieron
 * no cuentan: de esos avisa {@see ContingenciaVencida} (simulacro del 26/11, F20). Se
 * normaliza cuando llega el sello o cuando el aviso recibido le da al documento las
 * 72 h del lote.
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

    /**
     * Si solo quedan vencidos, este aviso no opina: abierto, no se da por normalizado al
     * vencer el plazo (el «Ya se normalizó» sería falso), y cerrado no manda un segundo
     * correo por lo mismo.
     */
    public function aplica(): bool
    {
        if (! config('dte.contingencia.enabled', false)) {
            return false;
        }
        $plazos = $this->lotes->plazosSinSello()->filter();

        return $plazos->contains(fn ($plazo) => $this->lotes->porVencer($plazo))
            || ! $plazos->contains(fn ($plazo) => HoraNegocio::ahora()->gt($plazo));
    }

    public function revisar(): ?Hallazgo
    {
        $plazos = $this->lotes->plazosSinSello()->filter(fn ($plazo) => $this->lotes->porVencer($plazo));

        if ($plazos->isEmpty()) {
            return null;
        }

        $n = $plazos->count();
        $horas = LoteDteService::HORAS_AVISO_POR_VENCER;
        // El «Desde:» del correo es cuando empezó a estar por vencer; la hora en que vence va acá.
        $vence = $plazos->min();

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento en contingencia está por vencer' : $n.' documentos en contingencia están por vencer',
            detalle: ($n === 1 ? 'Sigue sin sello de Hacienda y le quedan' : 'Siguen sin sello de Hacienda y les quedan').' menos de '.$horas.' horas de plazo: '
                .($n === 1 ? 'vence' : 'el primero vence').' el '.$vence->format('d/m/Y').' a las '.$vence->format('H:i').'.',
            queHacer: 'Abrí Contingencia: enviá el aviso de contingencia si falta, o revisá que los lotes salgan y queden aceptados. Pasado el plazo hay que pedir una prórroga al Ministerio de Hacienda.',
            conteo: $n,
            desde: $vence->copy()->subHours($horas),
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
