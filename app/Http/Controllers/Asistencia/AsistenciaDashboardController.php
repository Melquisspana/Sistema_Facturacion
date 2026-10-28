<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Support\Asistencia\PanelAsistencia;
use Illuminate\View\View;

/** Personas que marcaron hoy, según el día local de negocio. */
class AsistenciaDashboardController extends Controller
{
    public function index(PanelAsistencia $panel): View
    {
        return view('asistencia.dashboard', ['hoy' => $panel->hoy()]);
    }
}
