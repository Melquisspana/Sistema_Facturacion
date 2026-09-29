<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Support\Dinero;
use Illuminate\Support\Collection;

/**
 * Cuánto MONTO le queda a un CCF para acreditar con notas de crédito.
 *
 * Hacienda rechaza una nota que acredita más de lo que el documento relacionado tiene,
 * así que esto se frena antes: al elegir el CCF, al agregar o cambiar un concepto y,
 * como candado final, al generar.
 *
 * El total que se compara es `monto_total_operacion` en los dos lados: el bruto ANTES de
 * la retención de IVA (DteBorradorService::recalcular lo iguala a total_antes_retencion
 * en CCF y NC). Comparar el total a pagar mezclaría un neto de retención con un bruto.
 *
 * Saldo = total del CCF − totales de las OTRAS notas de crédito ya generadas sobre él
 * que siguen vigentes (generada, firmada, enviada o aceptada, sin invalidación). Un
 * borrador no cuenta: todavía no existe para Hacienda, y si llegara a generarse, el
 * candado de generar lo vuelve a medir contra lo que haya en ese momento.
 */
class SaldoMontoCcf
{
    private const ESTADOS_VIGENTES = [
        EstadoDte::Generado,
        EstadoDte::Firmado,
        EstadoDte::Enviado,
        EstadoDte::Aceptado,
    ];

    public function saldo(Dte $ccf, ?int $excluirNcId = null): string
    {
        return $this->saldos(collect([$ccf]), $excluirNcId)[$ccf->id];
    }

    /**
     * Saldos de varios CCF en UNA consulta (el buscador pide una página entera).
     *
     * @param  Collection<int, Dte>  $ccfs
     * @return array<int, string> [ccf_id => saldo]
     */
    public function saldos(Collection $ccfs, ?int $excluirNcId = null): array
    {
        if ($ccfs->isEmpty()) {
            return [];
        }

        $usado = Dte::query()
            ->where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereIn('dte_relacionado_id', $ccfs->pluck('id'))
            ->whereIn('estado', array_map(fn (EstadoDte $e) => $e->value, self::ESTADOS_VIGENTES))
            ->where(fn ($q) => $q->whereNull('sello_invalidacion')->orWhere('sello_invalidacion', ''))
            ->when($excluirNcId !== null, fn ($q) => $q->whereKeyNot($excluirNcId))
            ->groupBy('dte_relacionado_id')
            ->selectRaw('dte_relacionado_id, SUM(monto_total_operacion) AS usado')
            ->pluck('usado', 'dte_relacionado_id');

        return $ccfs->mapWithKeys(fn (Dte $c) => [
            $c->id => Dinero::redondear(Dinero::restar(
                $c->monto_total_operacion ?? '0',
                (string) ($usado[$c->id] ?? '0'),
            )),
        ])->all();
    }

    /**
     * Mensaje si la NC acredita más que el saldo de su CCF; null si cabe (o si no tiene
     * CCF relacionado, que es otro bloqueo con su propio aviso).
     */
    public function exceso(Dte $nc): ?string
    {
        if ($nc->tipo_dte !== TipoDte::NotaCredito || $nc->dte_relacionado_id === null) {
            return null;
        }

        $ccf = $nc->dteRelacionado()->first();
        if ($ccf === null) {
            return null;
        }

        $saldo = $this->saldo($ccf, $nc->id);
        $total = Dinero::redondear($nc->monto_total_operacion ?? '0');

        return Dinero::comparar($total, $saldo) > 0
            ? self::mensaje($ccf, $saldo, $total)
            : null;
    }

    public static function mensaje(Dte $ccf, string $saldo, string $total): string
    {
        return sprintf(
            'El CCF %s tiene un saldo de $%s, menor que la nota ($%s). Hacienda la rechazaría: elegí otro CCF o bajá el monto.',
            $ccf->numero_interno ?? $ccf->numero_control ?? ('#'.$ccf->id),
            number_format((float) $saldo, 2),
            number_format((float) $total, 2),
        );
    }
}
