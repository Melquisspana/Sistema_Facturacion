<?php

namespace App\Http\Controllers\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\JornadasRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;
use App\Support\Asistencia\ConsultaJornadas;
use Illuminate\View\View;

/**
 * Reporte de jornadas. SOLO CONSULTA.
 *
 * ─────────────── Este controlador no arma ni una jornada ───────────────
 *
 * No empareja marcaciones, no suma tiempos y no decide estados: todo eso vive en
 * {@see ConsultaJornadas}, que es la misma capa que va a usar el módulo de
 * Formatos. Si el emparejamiento viviera acá, Formatos tendría que copiarlo — y
 * el día que alguien corrigiera cómo se cuenta una hora, la pantalla y el
 * documento empezarían a decir cosas distintas de la misma semana.
 *
 * La vista tampoco calcula: recibe objetos `Jornada` ya resueltos.
 *
 * ────────────────────── Lo que sigue sin existir ──────────────────────
 *
 * Tardanzas, horas extra, ausencias y feriados. Necesitan una hora oficial de
 * entrada, una jornada pactada y un calendario laboral; ninguna de las tres está
 * declarada en el sistema, y una pantalla que las mostrara estaría inventando la
 * regla que después alguien discutiría en una planilla.
 */
class JornadaController extends Controller
{
    public function index(JornadasRequest $request, ConsultaJornadas $consulta, HoraOficial $horaOficial): View
    {
        $filtro = $request->filtro();
        $estado = $request->estado();

        return view('asistencia.jornadas.index', [
            'jornadas' => $consulta->paginar($filtro, $estado),
            'resumen' => $consulta->resumen($filtro, $estado),
            'filtro' => $filtro,
            'estado' => $estado,

            // Con los INACTIVOS: consultar las jornadas de quien ya no trabaja acá
            // es justamente para lo que sirve un reporte histórico.
            'empleados' => AsistenciaEmpleado::query()->orderBy('apellidos')->orderBy('nombres')->get(),
            'lectores' => AsistenciaDispositivo::query()->orderBy('nombre')->get(),
            'estados' => EstadoJornada::cases(),

            'zona' => $horaOficial->zona(),
        ]);
    }
}
