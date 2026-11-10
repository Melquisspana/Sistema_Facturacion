<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Services\Asistencia\HoraOficial;
use App\Support\Asistencia\HojaAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** La hoja diaria de asistencia para imprimir. Sin día elegido, la de hoy (día local). */
class HojaAsistenciaController extends Controller
{
    public function show(Request $request, HojaAsistencia $hoja, HoraOficial $horaOficial): View
    {
        $datos = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);
        $fecha = CarbonImmutable::parse($datos['fecha'] ?? $horaOficial->fechaLocal($horaOficial->instante()));

        return view('asistencia.hoja', ['fecha' => $fecha, 'filas' => $hoja->delDia($fecha)]);
    }
}
