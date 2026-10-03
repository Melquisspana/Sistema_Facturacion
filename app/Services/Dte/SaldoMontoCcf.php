<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Builder;
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
 * que siguen vigentes (generada, firmada, enviada o aceptada, sin invalidación REAL). Un
 * borrador no cuenta: todavía no existe para Hacienda, y si llegara a generarse, el
 * candado de generar lo vuelve a medir contra lo que haya en ese momento.
 *
 * El seguimiento de cobros usa la MISMA consulta de notas ({@see descontadoEnCobro()}),
 * con dos diferencias que no son otra regla sino otra pregunta: solo cuentan las NC ya
 * ACEPTADAS (el cliente descuenta lo que Hacienda recibió, no lo que está en camino), y
 * se suma `total_pagar`, porque el importe que se cobra del CCF también es su total a
 * pagar (neto de retención).
 */
class SaldoMontoCcf
{
    /**
     * Regla única del saldo: Rechazado es terminal y no reserva, esté archivado o no.
     * Borrador no existe para Hacienda; el candado al generar lo vuelve a medir.
     * Una invalidación MOCK es simulada: la NC sigue vigente para Hacienda.
     */
    private function notasQuePesan(Builder $consulta, array $ccfIds, ?int $excluirNcId = null): Builder
    {
        return $consulta->where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereIn('dte_relacionado_id', $ccfIds)
            ->whereIn('estado', array_map(fn (EstadoDte $e) => $e->value, self::ESTADOS_VIGENTES))
            ->where(fn ($q) => $q->whereNull('sello_invalidacion')->orWhere('sello_invalidacion', '')
                ->orWhereRaw('UPPER(sello_invalidacion) LIKE ?', ['MOCK%']))
            ->when($excluirNcId !== null, fn ($q) => $q->whereKeyNot($excluirNcId));
    }

    /** Cantidades acreditadas por otras notas, en una consulta para todas las líneas. */
    public function acreditadoPorLineas(Collection $originales, ?int $excluirNcId = null): array
    {
        if ($originales->isEmpty()) {
            return [];
        }

        return DteLinea::query()->whereIn('dte_linea_original_id', $originales->pluck('id'))
            ->whereHas('dte', fn (Builder $q) => $this->notasQuePesan($q, $originales->pluck('dte_id')->unique()->all(), $excluirNcId))
            ->selectRaw('dte_linea_original_id, SUM(cantidad) AS acreditado')
            ->groupBy('dte_linea_original_id')->pluck('acreditado', 'dte_linea_original_id')->all();
    }

    public function saldoLinea(DteLinea $original, ?int $excluirNcId = null): string
    {
        $usado = $this->acreditadoPorLineas(collect([$original]), $excluirNcId);

        return Dinero::restar(Dinero::de($original->cantidad), (string) ($usado[$original->id] ?? '0'));
    }

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

        $usado = $this->sumaDeNotas($ccfs->pluck('id')->all(), self::ESTADOS_VIGENTES, 'monto_total_operacion', $excluirNcId);

        return $ccfs->mapWithKeys(fn (Dte $c) => [
            $c->id => Dinero::redondear(Dinero::restar(
                $c->monto_total_operacion ?? '0',
                (string) ($usado[$c->id] ?? '0'),
            )),
        ])->all();
    }

    /**
     * Cuánto descuenta el cliente del cobro de cada CCF por sus notas de crédito: total a
     * pagar de las NC ACEPTADAS por Hacienda y sin invalidar. CCF sin notas → '0'.
     *
     * @param  array<int, int>  $ccfIds
     * @return array<int, string> [ccf_id => descuento]
     */
    public function descontadoEnCobro(array $ccfIds): array
    {
        $suma = $ccfIds === [] ? collect() : $this->sumaDeNotas($ccfIds, [EstadoDte::Aceptado], 'total_pagar');

        return collect($ccfIds)->mapWithKeys(fn (int $id) => [
            $id => Dinero::redondear((string) ($suma[$id] ?? '0')),
        ])->all();
    }

    /**
     * Las NC que pesan sobre cada CCF: relacionadas a él, en uno de los estados dados y sin
     * invalidación real. Un sello MOCK no libera saldo. La comparten emisión y cobro.
     *
     * @param  array<int, int>  $ccfIds
     * @param  array<int, EstadoDte>  $estados
     * @return Collection<int, string> [ccf_id => suma de la columna]
     */
    private function sumaDeNotas(array $ccfIds, array $estados, string $columna, ?int $excluirNcId = null): Collection
    {
        return $this->notasQuePesan(Dte::query(), $ccfIds, $excluirNcId)
            ->whereIn('estado', array_map(fn (EstadoDte $e) => $e->value, $estados))
            ->groupBy('dte_relacionado_id')
            ->selectRaw("dte_relacionado_id, SUM({$columna}) AS usado")
            ->pluck('usado', 'dte_relacionado_id');
    }

    /**
     * Mensaje si la NC acredita más que el saldo de su CCF; null si cabe (o si no tiene
     * CCF relacionado, que es otro bloqueo con su propio aviso).
     * Compara dos columnas: Hacienda rechaza por gravado (codigoMsg 016), mientras
     * monto_total_operacion también cubre lo exento y no sujeto.
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

        if (Dinero::comparar($total, $saldo) > 0) {
            return self::mensaje($ccf, $saldo, $total);
        }

        // Hacienda rechaza por gravado (016); el total también cubre lo exento/no sujeto.
        $usado = $this->sumaDeNotas([$ccf->id], self::ESTADOS_VIGENTES, 'total_gravado', $nc->id);
        $saldoGravado = Dinero::redondear(Dinero::restar($ccf->total_gravado ?? '0', (string) ($usado[$ccf->id] ?? '0')));
        $gravado = Dinero::redondear($nc->total_gravado ?? '0');
        if (Dinero::comparar($gravado, $saldoGravado) > 0) {
            return sprintf('El CCF %s tiene un saldo gravado de $%s, menor que el gravado de la nota ($%s). Hacienda la rechazaría: elegí otro CCF o bajá el monto.',
                $ccf->numero_interno ?? $ccf->numero_control ?? ('#'.$ccf->id), number_format((float) $saldoGravado, 2), number_format((float) $gravado, 2));
        }

        return null;
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
