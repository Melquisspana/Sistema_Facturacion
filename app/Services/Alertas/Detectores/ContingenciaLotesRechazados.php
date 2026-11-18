<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\LoteDte;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Dte\LoteDteService;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;

/**
 * Documentos emitidos en contingencia que volvieron a quedar sin lote porque Hacienda
 * rechazó entero el lote en que iban. La tarea los reintenta con espera y, desde el
 * tercer rechazo del mismo aviso, ya no sola ({@see LoteDteService::pausaTrasRechazos()}).
 * Se normaliza cuando vuelven a salir en un lote. Los de una contingencia vencida no
 * cuentan: de esos avisa {@see ContingenciaVencida}.
 */
class ContingenciaLotesRechazados implements Detector
{
    public function tipo(): string
    {
        return 'contingencia_lotes_rechazados';
    }

    public function nombre(): string
    {
        return 'Lote de contingencia rechazado por Hacienda';
    }

    public function aplica(): bool
    {
        return (bool) config('dte.contingencia.enabled', false);
    }

    public function revisar(): ?Hallazgo
    {
        $eventos = LoteDte::where('estado', 'error')->whereNotNull('contingencia_evento_id')->distinct()->pluck('contingencia_evento_id');
        $documentos = Dte::query()
            ->where('estado', EstadoDte::Firmado->value)
            ->whereIn('contingencia_evento_id', $eventos)
            ->whereNull('lote_dte_id')
            ->where(fn ($q) => $q->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
            ->whereHas('contingencia', fn ($q) => $q->where('estado', '!=', 'vencida'));

        $n = (clone $documentos)->count();
        if ($n === 0) {
            return null;
        }

        $desde = LoteDte::where('estado', 'error')
            ->whereIn('contingencia_evento_id', $documentos->distinct()->pluck('contingencia_evento_id'))->min('enviado_en');

        return new Hallazgo(
            titulo: $n === 1 ? '1 documento en contingencia quedó sin enviar por un lote rechazado'
                : $n.' documentos en contingencia quedaron sin enviar por un lote rechazado',
            detalle: 'Hacienda rechazó entero el lote en que iban. La tarea automática lo vuelve a intentar con más espera cada vez y, desde el tercer lote rechazado del mismo aviso, ya no los reenvía sola.',
            queHacer: 'Abrí Pendientes de envío, leé la respuesta de Hacienda en «Lotes rechazados al enviar», corregí la causa y apretá «Enviar a Hacienda». Ojo con el plazo de 72 horas del lote.',
            conteo: $n,
            // Se guardó con la hora del negocio.
            desde: $desde ? Carbon::parse((string) $desde, HoraNegocio::zona()) : null,
            enlace: 'facturacion.contingencia.pendientes',
        );
    }
}
