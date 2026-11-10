<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Support\Asistencia\AvisosAsistencia;
use App\Support\Asistencia\PanelAsistencia;
use Illuminate\View\View;

/** Personas que marcaron hoy, según el día local de negocio, y los avisos suaves. */
class AsistenciaDashboardController extends Controller
{
    public function index(PanelAsistencia $panel, AvisosAsistencia $avisos): View
    {
        return view('asistencia.dashboard', ['hoy' => $panel->hoy(), 'avisos' => $avisos->calcular()]);
    }
}
