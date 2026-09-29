<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Http\Controllers\Controller;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo del personal operativo: quién sale a vender, repartir, cobrar o quedar a cargo.
 *
 * Sin `destroy`, igual que las rutas: una persona que ya fue en salidas no se borra —se
 * llevaría por delante el historial de quién fue a dónde— sino que se desactiva.
 *
 * Los dos enlaces opcionales (usuario del sistema y empleado de Asistencia) son punteros de
 * IDENTIDAD para no duplicar a la misma persona, no dependencias: se pueden dejar vacíos y
 * todo funciona igual.
 */
class PersonalRutaController extends Controller
{
    public function index(Request $request): View
    {
        $personal = PersonalRuta::query()
            ->with(['funciones:id,rutas_personal_id,funcion', 'user:id,name'])
            ->withCount('participaciones')
            ->when($request->filled('q'), function ($q) use ($request) {
                $texto = '%'.trim($request->string('q')).'%';
                $q->where('nombre', 'like', $texto);
            })
            ->when($request->input('estado') === 'activos', fn ($q) => $q->where('activo', true))
            ->when($request->input('estado') === 'inactivos', fn ($q) => $q->where('activo', false))
            ->when($request->filled('funcion'), fn ($q) => $q->conFuncion(FuncionPersonalRuta::from($request->string('funcion')->toString())))
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('rutas.personal.index', [
            'personal' => $personal,
            'filtros' => $request->only(['q', 'estado', 'funcion']),
            'funciones' => FuncionPersonalRuta::cases(),
        ]);
    }

    public function create(): View
    {
        return view('rutas.personal.create', $this->datosFormulario());
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $personal = DB::transaction(function () use ($datos) {
            $persona = PersonalRuta::create([
                'nombre' => $datos['nombre'],
                'user_id' => $datos['user_id'] ?? null,
                'asistencia_empleado_id' => $datos['asistencia_empleado_id'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'activo' => true,
            ]);

            $this->sincronizarFunciones($persona, $datos['funciones'] ?? []);

            return $persona;
        });

        // Desde «Configurar rutas» el alta es en línea: se vuelve a la misma página.
        if ($request->boolean('en_linea')) {
            return back()->with('status', $personal->nombre.' quedó agregado como vendedor.');
        }

        return redirect()
            ->route('rutas.personal.show', $personal)
            ->with('status', $personal->nombre.' quedó dado de alta.');
    }

    public function show(PersonalRuta $personal): View
    {
        $personal->load([
            'funciones',
            'user:id,name',
            'empleado:id,nombres,apellidos',
            'participaciones.salida.ruta:id,nombre',
        ]);

        return view('rutas.personal.show', ['personal' => $personal]);
    }

    public function edit(PersonalRuta $personal): View
    {
        $personal->load('funciones');

        return view('rutas.personal.edit', $this->datosFormulario($personal) + ['personal' => $personal]);
    }

    public function update(Request $request, PersonalRuta $personal): RedirectResponse
    {
        $datos = $this->validar($request, $personal);

        DB::transaction(function () use ($personal, $datos) {
            $personal->update([
                'nombre' => $datos['nombre'],
                'user_id' => $datos['user_id'] ?? null,
                'asistencia_empleado_id' => $datos['asistencia_empleado_id'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'notas' => $datos['notas'] ?? null,
            ]);

            $this->sincronizarFunciones($personal, $datos['funciones'] ?? []);
        });

        return redirect()
            ->route('rutas.personal.show', $personal)
            ->with('status', 'Datos actualizados.');
    }

    /** Activa o desactiva a la persona. Su historial de salidas se conserva. */
    public function toggleActivo(PersonalRuta $personal): RedirectResponse
    {
        $personal->update(['activo' => ! $personal->activo]);

        $mensaje = $personal->activo
            ? $personal->nombre.' vuelve a estar disponible para salidas.'
            : $personal->nombre.' quedó inactivo: ya no se le asignan salidas.';

        return back()->with('status', $mensaje);
    }

    // ------------------------------------------------------------------ apoyo

    /** @return array<string, mixed> */
    private function validar(Request $request, ?PersonalRuta $personal = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'notas' => ['nullable', 'string', 'max:300'],

            // Únicos: una cuenta y un empleado no pueden ser dos personas de campo.
            'user_id' => ['nullable', 'integer', Rule::unique('rutas_personal', 'user_id')->ignore($personal?->id), 'exists:users,id'],
            'asistencia_empleado_id' => ['nullable', 'integer', Rule::unique('rutas_personal', 'asistencia_empleado_id')->ignore($personal?->id), 'exists:asistencia_empleados,id'],

            'funciones' => ['nullable', 'array'],
            'funciones.*' => [Rule::in(FuncionPersonalRuta::valores())],
        ], [
            'user_id.unique' => 'Ese usuario ya está enlazado a otra persona de campo.',
            'asistencia_empleado_id.unique' => 'Ese empleado ya está enlazado a otra persona de campo.',
        ], [
            'user_id' => 'usuario del sistema',
            'asistencia_empleado_id' => 'empleado',
        ]);
    }

    /**
     * Deja a la persona exactamente con estas funciones.
     *
     * Borra y vuelve a insertar en vez de calcular diferencias: son cuatro filas como mucho,
     * no llevan historial propio —el que importa es el de las salidas— y una
     * sincronización simple no puede quedar a medias.
     *
     * @param  array<int, string>  $funciones
     */
    private function sincronizarFunciones(PersonalRuta $personal, array $funciones): void
    {
        $personal->funciones()->delete();

        foreach (array_unique($funciones) as $funcion) {
            $personal->funciones()->create(['funcion' => $funcion]);
        }

        $personal->load('funciones');
    }

    /**
     * Datos de los formularios.
     *
     * Los selectores de usuario y empleado solo ofrecen los que NO están ya enlazados a otra
     * persona: ofrecer algo que la validación va a rechazar es peor que no ofrecerlo.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(?PersonalRuta $personal = null): array
    {
        $usuariosTomados = PersonalRuta::whereNotNull('user_id')
            ->when($personal, fn ($q) => $q->whereKeyNot($personal->id))
            ->pluck('user_id');

        $empleadosTomados = PersonalRuta::whereNotNull('asistencia_empleado_id')
            ->when($personal, fn ($q) => $q->whereKeyNot($personal->id))
            ->pluck('asistencia_empleado_id');

        return [
            'funciones' => FuncionPersonalRuta::cases(),
            'usuarios' => User::where('activo', true)
                ->whereNotIn('id', $usuariosTomados)
                ->orderBy('name')
                ->get(['id', 'name']),
            // Se LEE el catálogo de Asistencia solo para ofrecer el enlace de identidad.
            // Rutas no depende de ese módulo: si está apagado o la lista viene vacía, el
            // formulario funciona igual y el campo queda en null.
            'empleados' => AsistenciaEmpleado::where('activo', true)
                ->whereNotIn('id', $empleadosTomados)
                ->orderBy('nombres')
                ->get(['id', 'nombres', 'apellidos']),
        ];
    }
}
