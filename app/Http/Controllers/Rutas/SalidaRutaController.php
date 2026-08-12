<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoRevisionDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\SalidaRutaRequest;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\SeguimientoDocumentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Salidas de ruta: alta, edición y las tres acciones de estado.
 *
 * Los cambios de estado NO son ediciones de un campo: son actos con nombre propio
 * (iniciar, finalizar, cancelar), cada uno con su ruta, su permiso y su registro
 * de auditoría. El modelo valida la transición; acá se traduce a mensaje.
 *
 * Todavía SIN documentos: el conteo que muestra el detalle es 0 fijo hasta que
 * exista la relación con CCF/albaranes. Se muestra igual para que la pantalla ya
 * tenga su forma definitiva, pero no se inventa ningún dato.
 */
class SalidaRutaController extends Controller
{
    public function index(Request $request): View
    {
        $salidas = SalidaRuta::query()
            ->with(['ruta:id,nombre', 'vendedores:id,name'])
            // El conteo de documentos es la pregunta del listado; se resuelve en la
            // misma consulta y no fila por fila desde la vista.
            ->withCount('documentos')
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->when($request->filled('ruta_id'), fn ($q) => $q->where('ruta_id', $request->integer('ruta_id')))
            // Lo más reciente primero: el listado se abre para ver qué está pasando
            // ahora, no para leer el historial desde el principio.
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('rutas.salidas.index', [
            'salidas' => $salidas,
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
            'estados' => EstadoSalidaRuta::cases(),
        ]);
    }

    public function create(): View
    {
        return view('rutas.salidas.create', $this->datosFormulario());
    }

    public function store(SalidaRutaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $salida = SalidaRuta::create([
            'ruta_id' => $datos['ruta_id'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin_estimada' => $datos['fecha_fin_estimada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            // Nace PLANIFICADA aunque la fecha sea hoy: hasta que alguien confirme
            // que salió, la fecha de inicio es una intención. Iniciarla es un clic.
            'estado' => EstadoSalidaRuta::Planificada,
            'created_by' => $request->user()?->id,
        ]);

        $salida->vendedores()->sync($datos['vendedores']);
        $this->auditar($request, $salida, 'definió los participantes de la salida', [
            'vendedores' => $this->nombresDe($datos['vendedores']),
        ]);

        return redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', 'Salida creada como planificada. Cuando arranque, marcala como iniciada.');
    }

    /**
     * Detalle de la salida con su seguimiento documental.
     *
     * Los contadores y la lista salen de los MISMOS objetos ({@see SeguimientoDocumentos}),
     * así que no pueden contradecirse. Entrega y nota de crédito no se guardan en
     * ningún lado: se resuelven acá, en el momento, desde `ppq_albaranes` y `dtes`.
     */
    public function show(SalidaRuta $salida, SeguimientoDocumentos $seguimiento): View
    {
        $salida->load(['ruta', 'vendedores:id,name', 'creador:id,name']);

        $documentos = $seguimiento->documentosDe($salida);

        return view('rutas.salidas.show', [
            'salida' => $salida,
            'documentos' => $documentos,
            'resumen' => $seguimiento->resumen($documentos),
            // Destinos posibles para mover un documento: otras salidas abiertas.
            'destinos' => SalidaRuta::abiertas()
                ->whereKeyNot($salida->id)
                ->with('ruta:id,nombre')
                ->orderByDesc('fecha_inicio')
                ->get(),
            'motivos' => MotivoRevisionDocumento::cases(),
        ]);
    }

    public function edit(SalidaRuta $salida): View
    {
        abort_unless($salida->estado->esEditable(), 403, 'Una salida finalizada o cancelada ya no se edita.');

        $salida->load('vendedores:id');

        return view('rutas.salidas.edit', $this->datosFormulario() + ['salida' => $salida]);
    }

    public function update(SalidaRutaRequest $request, SalidaRuta $salida): RedirectResponse
    {
        abort_unless($salida->estado->esEditable(), 403, 'Una salida finalizada o cancelada ya no se edita.');

        $datos = $request->validated();

        $salida->update([
            'ruta_id' => $datos['ruta_id'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin_estimada' => $datos['fecha_fin_estimada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        // sync() devuelve qué cambió: solo se audita si de verdad cambió la gente,
        // para que el historial no se llene de "cambió los participantes" vacíos.
        $cambios = $salida->vendedores()->sync($datos['vendedores']);
        if ($cambios['attached'] !== [] || $cambios['detached'] !== []) {
            $this->auditar($request, $salida, 'cambió los participantes de la salida', [
                'agregados' => $this->nombresDe($cambios['attached']),
                'quitados' => $this->nombresDe($cambios['detached']),
            ]);
        }

        return redirect()
            ->route('rutas.salidas.show', $salida)
            ->with('status', 'Salida actualizada.');
    }

    // ------------------------------------------------------------- transiciones

    public function iniciar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        return $this->cambiarEstado($request, $salida, 'iniciar', 'inició la salida', 'Salida iniciada.');
    }

    public function finalizar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        return $this->cambiarEstado($request, $salida, 'finalizar', 'finalizó la salida', 'Salida finalizada.');
    }

    public function cancelar(Request $request, SalidaRuta $salida): RedirectResponse
    {
        return $this->cambiarEstado($request, $salida, 'cancelar', 'canceló la salida', 'Salida cancelada.');
    }

    /**
     * Aplica una transición y la audita. Si el modelo la rechaza (por ejemplo,
     * finalizar algo que nunca se inició) no se escribe nada y el usuario recibe
     * un error en vez de un cambio silencioso.
     */
    private function cambiarEstado(Request $request, SalidaRuta $salida, string $accion, string $descripcion, string $mensaje): RedirectResponse
    {
        $anterior = $salida->estado;

        if (! $salida->{$accion}()) {
            return back()->with('error', "No se puede {$accion} una salida {$anterior->label()}.");
        }

        $this->auditar($request, $salida, $descripcion, [
            'estado_anterior' => $anterior->value,
            'estado_nuevo' => $salida->estado->value,
        ]);

        return back()->with('status', $mensaje);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * Datos comunes de los formularios de alta y edición.
     *
     * Solo rutas ACTIVAS y usuarios ACTIVOS: el formulario no debería ofrecer algo
     * que la validación va a rechazar después.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(): array
    {
        return [
            'rutas' => Ruta::activas()->orderBy('nombre')->get(['id', 'nombre']),
            // Sin filtrar por rol: todavía no existe rol vendedor y cualquier
            // usuario activo puede salir a ruta.
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, string>
     */
    private function nombresDe(array $ids): array
    {
        return $ids === [] ? [] : User::whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    /** @param  array<string, mixed>  $propiedades */
    private function auditar(Request $request, SalidaRuta $salida, string $descripcion, array $propiedades): void
    {
        activity('salida_ruta')
            ->performedOn($salida)
            ->causedBy($request->user())
            ->withProperties($propiedades)
            ->log($descripcion);
    }
}
