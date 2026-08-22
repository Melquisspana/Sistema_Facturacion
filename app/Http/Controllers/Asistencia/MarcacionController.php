<?php

namespace App\Http\Controllers\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\HistorialRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;
use App\Support\Asistencia\ConsultaAsistencia;
use Illuminate\View\View;

/**
 * Historial de marcaciones. SOLO CONSULTA.
 *
 * ─────────────────── Este controlador no sabe consultar ───────────────────
 *
 * No tiene un solo `where`. Los criterios los arma el FormRequest y la consulta
 * la resuelve {@see ConsultaAsistencia}, que es la misma que va a usar el módulo
 * de Formatos. Si el filtro de fechas viviera acá, Formatos tendría que copiarlo
 * —y el día que alguien corrigiera un criterio, la pantalla y el documento
 * empezarían a decir cosas distintas sobre el mismo mes—.
 *
 * ─────────────────────── APPEND-ONLY: no hay más verbos ───────────────────────
 *
 * Solo existe `index`. No hay `edit`, ni `update`, ni `destroy`, y no es un
 * olvido: una marcación es un hecho ya ocurrido y la tabla ni siquiera tiene
 * `updated_at` con el que disimular un cambio. Cuando exista la corrección
 * manual, será una fila NUEVA con `origen = 'manual'` que anule a la anterior,
 * nunca una edición encima del hecho.
 *
 * ────────────────────── Lo que todavía no calcula ──────────────────────
 *
 * Horas trabajadas, jornadas, tardanzas. Necesitan horarios, y los horarios no
 * existen: emparejar una entrada con una salida sin saber qué es una jornada es
 * adivinar. La cabecera muestra CONTEOS —marcaciones, personas, días— y ahí se
 * detiene.
 */
class MarcacionController extends Controller
{
    public function index(HistorialRequest $request, ConsultaAsistencia $consulta, HoraOficial $horaOficial): View
    {
        $filtro = $request->filtro();

        return view('asistencia.marcaciones.index', [
            'marcaciones' => $consulta->paginar($filtro),
            'resumen' => $consulta->resumen($filtro),
            'filtro' => $filtro,

            // Los desplegables incluyen a los INACTIVOS a propósito: el historial
            // de quien ya no trabaja acá tiene que poder consultarse, y un lector
            // retirado sigue siendo el que registró lo que registró. Filtrar las
            // opciones por `activo` escondería justamente lo que se viene a buscar.
            'empleados' => AsistenciaEmpleado::query()->orderBy('apellidos')->orderBy('nombres')->get(),
            'lectores' => AsistenciaDispositivo::query()->orderBy('nombre')->get(),
            'tipos' => TipoMarcacion::cases(),

            'zona' => $horaOficial->zona(),
        ]);
    }
}
