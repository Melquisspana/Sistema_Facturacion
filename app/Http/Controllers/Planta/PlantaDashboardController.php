<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Panel de inicio del área Producción (planta).
 *
 * Fase 1: pantalla informativa. Deliberadamente NO consulta la base de datos, no
 * inyecta servicios, no cachea y no muestra ningún indicador: no hay datos
 * operativos que resumir todavía y un KPI inventado es peor que ninguno.
 *
 * La autorización completa (auth + interruptor del módulo + permiso planta.ver)
 * la resuelve el middleware de routes/planta.php antes de llegar aquí.
 */
class PlantaDashboardController extends Controller
{
    public function index(): View
    {
        return view('planta.dashboard');
    }
}
