<?php

namespace App\Http\Controllers\Contabilidad;

use App\Http\Controllers\Controller;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\Reportes\ReporteContadoraQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Paquete mensual para contabilidad (herramienta INTERNA; la contadora no entra al
 * sistema). Junta COMPRAS (documentos recibidos) y VENTAS (reporte contadora) por
 * rango, muestra un resumen y genera un ZIP para enviarlo por fuera.
 *
 * SOLO LECTURA: no vuelve a descargar correos, no envía nada, no toca DTE emitidos,
 * correlativos, firmador ni transmisión. No cambia estados al generar el ZIP.
 */
class PaqueteContabilidadController extends Controller
{
    public function index(Request $request): View
    {
        $rango = $this->rango($request);
        $compras = $this->compras($rango);
        $ventas = $this->ventas($rango);

        return view('contabilidad.paquete', [
            'rango' => $rango,
            'incluirCompras' => $request->boolean('incluir_compras', true),
            'incluirVentas' => $request->boolean('incluir_ventas', true),
            'resumen' => [
                'compras_cantidad' => $compras->count(),
                'compras_total' => round((float) $compras->sum('total'), 2),
                'ventas_cantidad' => $ventas->count(),
                'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            ],
        ]);
    }

    public function generar(Request $request, PaqueteContabilidadZip $zip): BinaryFileResponse|RedirectResponse
    {
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);
        if (! $incluirCompras && ! $incluirVentas) {
            return back()->with('error', 'Elegí al menos una fuente (compras o ventas) para generar el paquete.');
        }

        $rango = $this->rango($request);
        $compras = $incluirCompras ? $this->compras($rango) : new Collection();
        $ventas = $incluirVentas ? $this->ventas($rango) : new Collection();

        $r = $zip->generar($rango['etiqueta'], $compras, $ventas, $incluirCompras, $incluirVentas);

        return response()
            ->download($r['ruta'], $zip->nombreArchivo($rango['etiqueta']))
            ->deleteFileAfterSend();
    }

    /** Compras del rango (documentos recibidos). Reutiliza el query del módulo. */
    private function compras(array $rango): Collection
    {
        $f = DocumentosRecibidosQuery::filtros([
            'vista' => 'bandeja', 'rango' => 'personalizado',
            'fecha_desde' => $rango['desde'], 'fecha_hasta' => $rango['hasta'],
        ]);

        return DocumentosRecibidosQuery::query($f)->orderBy('fecha_correo')->get();
    }

    /** Ventas del rango (documentos emitidos). Reutiliza el query del Reporte contadora. */
    private function ventas(array $rango): Collection
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
    private function rango(Request $request): array
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
