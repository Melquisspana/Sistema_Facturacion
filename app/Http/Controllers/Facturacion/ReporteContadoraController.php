<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reporte para la contadora: listado + Excel de los DTE de ESTE sistema. SOLO
 * LECTURA. No emite, no transmite, no toca correlativos ni envía correos. Por
 * defecto excluye pruebas/mock (ambiente 01 + aceptados reales por Hacienda).
 */
class ReporteContadoraController extends Controller
{
    /** Pantalla con filtros + vista previa de resultados. */
    public function index(Request $request): View
    {
        $filtros = ReporteContadoraQuery::filtros($request->all());

        $dtes = ReporteContadoraQuery::query($filtros)
            ->limit(500) // vista previa acotada; el Excel exporta todo el rango
            ->get();

        return view('facturacion.reporte-contadora', [
            'filtros' => $filtros,
            'tipos' => ReporteContadoraQuery::TIPOS,
            'dtes' => $dtes,
        ]);
    }

    /** Descarga el Excel con TODOS los documentos del rango filtrado. */
    public function exportar(Request $request, ReporteContadoraExcel $excel): BinaryFileResponse
    {
        $filtros = ReporteContadoraQuery::filtros($request->all());

        $dtes = ReporteContadoraQuery::query($filtros)->get();

        $ruta = $excel->generar($dtes);

        return response()
            ->download($ruta, $excel->nombreArchivo($filtros['fecha_desde'], $filtros['fecha_hasta']))
            ->deleteFileAfterSend();
    }
}
