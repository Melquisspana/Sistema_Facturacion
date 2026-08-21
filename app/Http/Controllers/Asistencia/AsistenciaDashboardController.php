<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Support\Asistencia\PanelAsistencia;
use Illuminate\View\View;

/**
 * Pantalla de inicio del área Asistencia.
 *
 * SOLO LECTURA y solo CONTEOS de cosas que existen. No hay tardanzas, ni
 * ausencias, ni horas trabajadas: esas cifras necesitan horarios, y los horarios
 * no existen. Un indicador inventado en una pantalla de inicio es peor que
 * ninguno, porque nadie vuelve a dudar de él.
 *
 * Los conteos los calcula {@see PanelAsistencia}, no este
 * controlador: el módulo de Formatos va a necesitar los mismos números y una
 * consulta con nombre propio se reutiliza sin copiarse.
 *
 * El candado del área —auth + interruptor del módulo + `asistencia.ver`— lo
 * resuelve el middleware de routes/asistencia.php antes de llegar acá.
 */
class AsistenciaDashboardController extends Controller
{
    public function index(PanelAsistencia $panel): View
    {
        return view('asistencia.dashboard', ['resumen' => $panel->resumen()]);
    }
}
