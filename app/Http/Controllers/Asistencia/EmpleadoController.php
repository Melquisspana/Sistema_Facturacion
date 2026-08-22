<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistencia\EmpleadoRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\HoraOficial;
use App\Support\Asistencia\ConsultaAsistencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Personas que marcan asistencia. Alta, edición y activación.
 *
 * ─────────────────────────── NO HAY ELIMINACIÓN ───────────────────────────
 *
 * No existe `destroy()` ni ruta que lo invoque, y no es un olvido: borrar a
 * alguien borra su historial laboral. La base lo respalda —`restrictOnDelete`
 * desde marcaciones y huellas—, pero la garantía de verdad es que **no hay
 * endpoint**: no hace falta acordarse de comprobar nada porque no hay por dónde
 * pedirlo. Quien deja la empresa se DESACTIVA: sus marcaciones siguen ahí y su
 * huella deja de abrir la puerta.
 *
 * ─────────────────── La ficha muestra sus últimas marcaciones ───────────────────
 *
 * Y las pide a {@see ConsultaAsistencia}, la misma capa que alimenta el historial
 * completo y que alimentará al módulo de Formatos. Un `AsistenciaMarcacion::where()`
 * escrito acá funcionaría hoy y garantizaría que, en cuanto alguien afine el
 * criterio en un sitio, las dos pantallas empezaran a discrepar sobre la misma
 * persona.
 *
 * ─────────────── `asistencia_empleados` no es `users`, y sigue sin serlo ───────────────
 *
 * Este formulario no toca `user_id`. El enlace opcional con la cuenta del sistema
 * existe en el esquema, pero atarlo desde aquí dejaría que quien administra
 * personal asocie a una persona con un usuario —y con sus permisos fiscales—.
 */
class EmpleadoController extends Controller
{
    public function index(Request $request): View
    {
        $empleados = AsistenciaEmpleado::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $buscar = '%'.$request->string('q').'%';
                $query->where(fn ($w) => $w->where('nombres', 'like', $buscar)
                    ->orWhere('apellidos', 'like', $buscar)
                    ->orWhere('codigo', 'like', $buscar));
            })
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            // Se cuentan las huellas VIGENTES, no todas: alguien con tres
            // asignaciones históricas y ninguna activa no puede marcar, y la
            // pantalla tiene que decir eso y no «3».
            ->withCount(['huellas as huellas_activas_count' => fn ($q) => $q->where('activo', true)])
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate(25)
            ->withQueryString();

        return view('asistencia.empleados.index', ['empleados' => $empleados]);
    }

    /**
     * Ficha de la persona: sus datos y TODAS sus asignaciones de ranura, vigentes
     * e históricas. Es la pantalla desde la que se asigna y se libera, porque «qué
     * ranura es de quién» solo se entiende mirando a la persona completa.
     */
    public function show(AsistenciaEmpleado $empleado, ConsultaAsistencia $consulta, HoraOficial $horaOficial): View
    {
        $empleado->load([
            'huellas' => fn ($q) => $q->with('dispositivo')
                // Vigentes primero; dentro de cada grupo, la más reciente arriba.
                ->orderByDesc('activo')
                ->orderByDesc('id'),
        ]);

        return view('asistencia.empleados.show', [
            'empleado' => $empleado,
            // Solo lectores ACTIVOS: asignar una ranura de un lector desactivado
            // crearía una asignación que no puede marcar nada.
            'lectores' => AsistenciaDispositivo::query()->where('activo', true)->orderBy('nombre')->get(),

            // Las últimas marcaciones, por la MISMA capa de consulta que usa el
            // historial completo. La ficha no construye su propia consulta: si lo
            // hiciera, el día que cambie el orden o el criterio, las dos pantallas
            // dirían cosas distintas de la misma persona.
            'ultimas' => $consulta->ultimasDe($empleado->id),
            'zona' => $horaOficial->zona(),
        ]);
    }

    public function create(): View
    {
        return view('asistencia.empleados.create', ['empleado' => new AsistenciaEmpleado]);
    }

    public function store(EmpleadoRequest $request): RedirectResponse
    {
        $empleado = AsistenciaEmpleado::create($request->validated() + ['activo' => true]);

        return redirect()
            ->route('asistencia.empleados.show', $empleado)
            ->with('status', "«{$empleado->nombreCompleto()}» dado de alta. Asignale una ranura del lector para que pueda marcar.");
    }

    public function edit(AsistenciaEmpleado $empleado): View
    {
        return view('asistencia.empleados.edit', ['empleado' => $empleado]);
    }

    public function update(EmpleadoRequest $request, AsistenciaEmpleado $empleado): RedirectResponse
    {
        $empleado->update($request->validated());

        return redirect()
            ->route('asistencia.empleados.show', $empleado)
            ->with('status', 'Datos actualizados.');
    }

    /**
     * Activar o desactivar. Desactivar NO libera sus ranuras a propósito: la
     * plantilla sigue guardada en el sensor y la asignación sigue siendo suya. Lo
     * que cambia es que el endpoint del lector responde `empleado_inactivo` en vez
     * de registrar la marcación.
     *
     * Liberar la ranura es un acto aparte, porque implica borrarla físicamente del
     * AS608 y ese paso no lo puede hacer el servidor.
     */
    public function toggleActivo(AsistenciaEmpleado $empleado): RedirectResponse
    {
        $empleado->update(['activo' => ! $empleado->activo]);

        $activas = $empleado->huellas()->where('activo', true)->count();

        $mensaje = $empleado->activo
            ? "«{$empleado->nombreCompleto()}» vuelve a marcar."
            : "«{$empleado->nombreCompleto()}» ya no puede marcar. Su historial se conserva.";

        if (! $empleado->activo && $activas > 0) {
            $mensaje .= ' Conserva '.$activas.' ranura(s) asignada(s): liberalas si querés reutilizarlas para otra persona.';
        }

        return back()->with('status', $mensaje);
    }
}
