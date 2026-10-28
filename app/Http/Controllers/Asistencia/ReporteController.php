<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\ReporteRequest;
use App\Services\Asistencia\ReporteAsistenciaExcel;
use App\Support\Asistencia\ReporteAsistencia;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReporteController extends Controller
{
    public function index(ReporteRequest $request, ReporteAsistencia $reporte): View
    {
        $filtro = $request->filtro();
        $filas = $reporte->filas($filtro);

        return view('asistencia.reporte.index', compact('filtro', 'filas'));
    }

    public function excel(ReporteRequest $request, ReporteAsistencia $reporte, ReporteAsistenciaExcel $excel): BinaryFileResponse
    {
        $filtro = $request->filtro();
        $nombre = 'asistencia_'.$filtro->desde->format('Y-m-d').'_'.$filtro->hasta->format('Y-m-d').'.xlsx';

        return response()->download($excel->generar($reporte->filas($filtro)), $nombre)->deleteFileAfterSend(true);
    }
}
