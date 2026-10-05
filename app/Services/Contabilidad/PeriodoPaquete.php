<?php

namespace App\Services\Contabilidad;

use App\Models\DocumentoRecibido;
use App\Services\Reportes\ReporteContadoraQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Período y documentos del paquete de contabilidad. Lo usan la pantalla y los jobs que
 * arman el ZIP en segundo plano, para que los tres vean exactamente el mismo recorte.
 */
class PeriodoPaquete
{
    /**
     * Compras del período, por FECHA FISCAL (la de emisión del documento).
     *
     * Antes se recortaba por `fecha_correo` reutilizando el filtro de la pantalla de
     * Compras. Eso ponía cada CCF en el mes en que llegó el correo, no en el que se
     * emitió: un CCF del 31 de agosto que el proveedor manda el 2 de septiembre caía en
     * septiembre, y uno emitido el 1 de septiembre que llegó adelantado caía en agosto.
     * Para contabilidad eso es un documento en el período equivocado. Las ventas siempre
     * se recortaron por `fecha_emision`; ahora las dos fuentes usan el mismo criterio.
     *
     * También se excluye lo marcado `ignorado`: el filtro anterior (`vista: bandeja`) no
     * filtraba por estado, así que lo que alguien había apartado a propósito viajaba
     * igual a la contadora.
     *
     * Las compras SIN fecha fiscal legible no entran en ningún período. No se cuelan por
     * la fecha del correo: se cuentan aparte en la cobertura para que alguien las
     * resuelva ({@see CoberturaPaquete}).
     *
     * @param  array{desde: string, hasta: string}  $rango
     */
    public function compras(array $rango): Collection
    {
        return DocumentoRecibido::query()
            ->paraContabilidad()
            ->periodoFiscal($rango['desde'], $rango['hasta'])
            ->orderBy('fecha_dte')->orderBy('id')
            ->get();
    }

    /**
     * Ventas del rango (documentos emitidos). Reutiliza el query del Reporte contadora.
     *
     * @param  array{desde: string, hasta: string}  $rango
     */
    public function ventas(array $rango): Collection
    {
        $f = ReporteContadoraQuery::filtros([
            'fecha_desde' => $rango['desde'], 'fecha_hasta' => $rango['hasta'],
        ]);

        return ReporteContadoraQuery::query($f)->get();
    }

    /**
     * Resuelve el rango: fecha_desde/hasta explícitas, o mes+año (default mes actual).
     *
     * @return array{desde: string, hasta: string, etiqueta: string, mes: int, anio: int}
     */
    public function rango(Request $request): array
    {
        $desde = $this->fecha($request->input('fecha_desde'));
        $hasta = $this->fecha($request->input('fecha_hasta'));

        if ($desde && $hasta) {
            $d = Carbon::parse($desde);
            $h = Carbon::parse($hasta);
            $etiqueta = $d->isSameMonth($h) ? $d->format('Y-m') : $d->format('Y-m-d').'_a_'.$h->format('Y-m-d');

            return ['desde' => $desde, 'hasta' => $hasta, 'etiqueta' => $etiqueta, 'mes' => (int) $d->month, 'anio' => (int) $d->year];
        }

        $mes = max(1, min(12, (int) $request->input('mes', now()->month)));
        $anio = (int) $request->input('anio', now()->year);
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();

        return [
            'desde' => $inicio->toDateString(),
            'hasta' => $inicio->copy()->endOfMonth()->toDateString(),
            'etiqueta' => $inicio->format('Y-m'),
            'mes' => $mes,
            'anio' => $anio,
        ];
    }

    private function fecha(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
