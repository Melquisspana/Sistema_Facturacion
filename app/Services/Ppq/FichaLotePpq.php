<?php

namespace App\Services\Ppq;

use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Support\Albaran;
use App\Support\NumeroAlbaran;
use App\Support\OrdenCompra;
use App\Support\PpqConciliacion;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La ficha de un lote PPQ con CIENTOS de documentos, sin hidratar cientos de modelos.
 *
 * ═══════════════ Por qué el orden no va en SQL ═══════════════
 *
 * El orden es el del Excel de Calleja ({@see PpqLote::itemsOrdenados()}): primero los CCF,
 * después las NC, y dentro de cada grupo por el CORRELATIVO numérico final del número de
 * control, con el id como desempate. Ese correlativo sale de `preg_match('/(\d+)$/')`
 * sobre el control del item —o el del DTE si el item no lo trae— y un número no estándar
 * cuenta como 0. Reproducir eso en SQL igual en MySQL y SQLite (dígitos finales de
 * longitud variable, controles sin dígitos) no es fiable.
 *
 * Así que se lee una lista LIGERA —una fila de arreglos por item, sin modelos ni
 * relaciones—, se ordena en PHP con la MISMA regla y se hidratan con sus relaciones solo
 * los 25 de la página. De esa misma lista salen los totales del lote COMPLETO.
 *
 * El Excel oficial y la conciliación no pasan por acá: siguen procesando el lote entero.
 */
class FichaLotePpq
{
    public const POR_PAGINA = 25;

    /**
     * Filas ligeras de todos los items del lote, en orden de id.
     *
     * Los JOIN respetan lo que haría la relación: un DTE o un albarán eliminados (soft
     * delete) no aportan datos, igual que `$item->dte` / `$item->albaran` darían null.
     *
     * @return Collection<int, object>
     */
    public function filas(PpqLote $lote): Collection
    {
        return DB::table('ppq_items as i')
            ->leftJoin('dtes as d', fn ($j) => $j->on('d.id', '=', 'i.dte_id')->whereNull('d.deleted_at'))
            ->leftJoin('ppq_albaranes as a', fn ($j) => $j->on('a.id', '=', 'i.ppq_albaran_id')->whereNull('a.deleted_at'))
            // Albarán propio de la NC (el registrado al emitirla): de ahí sale si es AC04.
            ->leftJoin('dte_albaranes as da', 'da.dte_id', '=', 'i.dte_id')
            ->where('i.ppq_lote_id', $lote->id)
            ->orderBy('i.id')
            ->get([
                'i.id', 'i.tipo_dte', 'i.numero_control', 'i.monto_dte', 'i.monto_albaran', 'i.diferencia',
                'i.sin_albaran', 'i.ppq_albaran_id', 'i.numero_orden_compra', 'i.fecha_documento',
                'd.tipo_dte as dte_tipo', 'd.numero_control as dte_control', 'd.fecha_emision as dte_fecha',
                'a.numero_albaran',
                'da.numero_canonico as nc_albaran', 'da.total as nc_albaran_total',
            ]);
    }

    /**
     * Ids en el orden del Excel: CCF antes que NC, correlativo ascendente, id.
     *
     * @param  Collection<int, object>  $filas
     * @return array<int, int>
     */
    public function idsOrdenados(Collection $filas): array
    {
        return $filas
            ->sortBy([
                fn ($x, $y) => $this->ordenTipo($x) <=> $this->ordenTipo($y),
                fn ($x, $y) => $this->correlativo($x) <=> $this->correlativo($y),
                fn ($x, $y) => (int) $x->id <=> (int) $y->id,
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Ids en el orden de la PANTALLA: CCF antes que NC y, dentro de cada grupo, los más
     * recientes primero (fecha del documento, luego correlativo, luego id; sin fecha al
     * final). Los archivos para Calleja siguen usando {@see idsOrdenados()}.
     *
     * @param  Collection<int, object>  $filas
     * @return array<int, int>
     */
    public function idsRecientesPrimero(Collection $filas): array
    {
        return $filas
            ->sortBy([
                fn ($x, $y) => $this->ordenTipo($x) <=> $this->ordenTipo($y),
                fn ($x, $y) => $this->fecha($y) <=> $this->fecha($x),
                fn ($x, $y) => $this->correlativo($y) <=> $this->correlativo($x),
                fn ($x, $y) => (int) $y->id <=> (int) $x->id,
            ])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Fecha del documento como 'YYYY-MM-DD' ('' si no hay: va al final). */
    private function fecha(object $f): string
    {
        return substr((string) ($f->fecha_documento ?? $f->dte_fecha ?? ''), 0, 10);
    }

    /**
     * Totales y conteos del lote COMPLETO, con las mismas reglas que los métodos de
     * {@see PpqLote} (totalMontoDte, cantidadSinAlbaran, etc.).
     *
     * @param  Collection<int, object>  $filas
     * @return array{cantidad: int, total_dte: float, total_albaran: float, diferencia: float, sin_albaran: int, sin_monto: int, otra_sala: int, con_diferencia: int}
     */
    public function resumen(Collection $filas): array
    {
        $tolerancia = (float) config('ppq.diferencia_coincide', 0.05);

        $totalDte = 0.0;
        $totalAlb = 0.0;
        $sinAlbaran = 0;
        $sinMonto = 0;
        $otraSala = 0;
        $conDiferencia = 0;
        $explicada = 0.0;
        $explicadas = [];

        // Devoluciones (NC con albarán AC04) por sala: Calleja ya las descontó del albarán
        // de entrega, con su descuento; la NC va sin descuento. Esa diferencia del CCF es
        // legítima y la cubre la NC.
        $devoluciones = [];
        foreach ($filas as $f) {
            $partes = NumeroAlbaran::desde($f->nc_albaran ?? null);
            if ($this->ordenTipo($f) === 1 && $partes?->tipo === 'AC04' && $partes->sala !== null) {
                $devoluciones[$partes->sala] = ($devoluciones[$partes->sala] ?? 0.0) + abs((float) $f->monto_dte);
            }
        }

        foreach ($filas as $f) {
            $esNc = $this->ordenTipo($f) === 1;
            $signo = $esNc ? -1 : 1;
            $totalDte += $signo * (float) $f->monto_dte;

            // Una NC sin albarán PPQ usa el de crédito registrado al emitirla.
            $montoAlbaran = $f->monto_albaran ?? ($esNc && $f->nc_albaran_total !== null ? abs((float) $f->nc_albaran_total) : null);
            if ($montoAlbaran !== null) {
                $totalAlb += $signo * (float) $montoAlbaran;
            }

            $tieneAlbaran = (! (bool) $f->sin_albaran && $f->ppq_albaran_id !== null) || ($esNc && $f->nc_albaran !== null);
            if (! $tieneAlbaran) {
                $sinAlbaran++;
            } elseif (! $esNc || $f->ppq_albaran_id !== null) {
                if ($f->monto_albaran === null && ! $esNc) {
                    $sinMonto++;
                }
                $salaAlbaran = Albaran::salaDesdeNumero($f->numero_albaran);
                if (! $esNc && PpqConciliacion::salaMismatch(OrdenCompra::salaDesde($f->numero_orden_compra), $salaAlbaran) !== null) {
                    $otraSala++;
                }
            }

            if ($f->monto_albaran === null || abs((float) $f->diferencia) <= $tolerancia) {
                continue;
            }
            $dif = (float) $f->diferencia;
            $sala = Albaran::salaDesdeNumero($f->numero_albaran);
            if (! $esNc && $dif > 0 && $sala !== null && ($devoluciones[$sala] ?? 0.0) + $tolerancia >= $dif) {
                $devoluciones[$sala] -= $dif;
                $explicada += $dif;
                $explicadas[] = (int) $f->id;

                continue;
            }
            $conDiferencia++;
        }

        $totalDte = round($totalDte, 2);
        $totalAlb = round($totalAlb, 2);

        return [
            'cantidad' => $filas->count(),
            'total_dte' => $totalDte,
            'total_albaran' => $totalAlb,
            'diferencia' => round($totalDte - $totalAlb, 2),
            'diferencia_devoluciones' => round($explicada, 2),
            'diferencia_sin_explicar' => round($totalDte - $totalAlb - $explicada, 2),
            'explicadas' => $explicadas,
            'sin_albaran' => $sinAlbaran,
            'sin_monto' => $sinMonto,
            'otra_sala' => $otraSala,
            'con_diferencia' => $conDiferencia,
        ];
    }

    /**
     * La página visible, con sus relaciones, en el orden de `$ids`. El parámetro `page`
     * inválido (texto, arreglo, cero) vale 1.
     *
     * @param  array<int, int>  $ids  ids ya ordenados
     * @return LengthAwarePaginator<PpqItem>
     */
    public function pagina(array $ids): LengthAwarePaginator
    {
        $pagina = Paginator::resolveCurrentPage('page');
        $corte = array_slice($ids, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        $posicion = array_flip($corte);
        $items = $corte === []
            ? collect()
            : PpqItem::query()
                ->whereIn('id', $corte)
                ->with([
                    'dte:id,tipo_dte,numero_control,codigo_generacion,sello_recepcion,fecha_emision,total_pagar,numero_orden_compra,cliente_sucursal_id,dte_relacionado_id',
                    'dte.clienteSucursal:id,nombre,codigo',
                    'albaran:id,numero_albaran,tipo_codigo,fecha_albaran,monto_albaran,sala_codigo',
                ])
                ->get()
                ->sortBy(fn (PpqItem $i) => $posicion[$i->id])
                ->values();

        return (new LengthAwarePaginator($items, count($ids), self::POR_PAGINA, $pagina, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]))->withQueryString();
    }

    /** 1 si es NC (resta), 0 si no. Misma regla que {@see PpqItem::ordenTipo()}. */
    private function ordenTipo(object $f): int
    {
        return (($f->tipo_dte ?? $f->dte_tipo) === '05') ? 1 : 0;
    }

    /** Dígitos finales del control. Misma regla que {@see PpqItem::correlativoNumero()}. */
    private function correlativo(object $f): int
    {
        preg_match('/(\d+)$/', (string) ($f->numero_control ?? $f->dte_control), $m);

        return (int) ($m[1] ?? 0);
    }
}
