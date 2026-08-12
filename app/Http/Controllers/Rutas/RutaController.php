<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rutas\RutaRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de rutas. CRUD sin eliminación: la acción visible es activar/desactivar,
 * que conserva las salas asignadas y el historial de salidas.
 *
 * El detalle de una ruta ({@see show()}) es además la pantalla donde se administran
 * sus salas habituales; el alta/baja concreta la hace {@see RutaSalaController}.
 */
class RutaController extends Controller
{
    /** Resultados del buscador de salas. Suficiente para elegir sin scroll infinito. */
    private const SALAS_POR_PAGINA = 15;

    public function index(Request $request): View
    {
        $rutas = Ruta::query()
            ->when($request->filled('q'), fn ($q) => $q->where('nombre', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('activa'), fn ($q) => $q->where('activa', $request->boolean('activa')))
            // El conteo de salas es la pregunta que se hace mirando el listado
            // («¿esta ruta tiene salas o está vacía?»), así que se resuelve acá y
            // no con una consulta por fila en la vista.
            ->withCount('sucursales')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('rutas.rutas.index', ['rutas' => $rutas]);
    }

    public function create(): View
    {
        return view('rutas.rutas.create');
    }

    public function store(RutaRequest $request): RedirectResponse
    {
        $ruta = Ruta::create($request->validated());

        return redirect()
            ->route('rutas.rutas.show', $ruta)
            ->with('status', "Ruta «{$ruta->nombre}» creada.");
    }

    /**
     * Detalle de la ruta: sus salas habituales y el buscador para asignar más.
     *
     * El buscador NO lista las 135 sucursales de golpe. Solo muestra resultados
     * cuando hay algo que buscar o un filtro puesto, y de a
     * {@see SALAS_POR_PAGINA}. Cada resultado indica si ya tiene otra ruta, para
     * que reasignar sea una decisión informada y no un descubrimiento posterior.
     */
    public function show(Request $request, Ruta $ruta): View
    {
        $asignadas = $ruta->sucursales()
            ->with('cliente:id,nombre')
            ->orderBy('nombre')
            ->get();

        $busco = $request->filled('q') || $request->filled('cliente_id') || $request->boolean('todas');

        $candidatas = null;
        if ($busco) {
            $candidatas = ClienteSucursal::query()
                // Nunca las de esta ruta (ya están en la columna de al lado) y
                // nunca las dadas de baja: el scope de SoftDeletes las excluye solo.
                ->where(fn ($q) => $q->whereNull('ruta_id')->orWhere('ruta_id', '!=', $ruta->id))
                ->when($request->filled('q'), function ($q) use ($request) {
                    $buscar = '%'.$request->string('q').'%';
                    $q->where(fn ($w) => $w->where('nombre', 'like', $buscar)->orWhere('codigo', 'like', $buscar));
                })
                ->when($request->filled('cliente_id'), fn ($q) => $q->where('cliente_id', $request->integer('cliente_id')))
                ->when(! $request->boolean('incluir_inactivas'), fn ($q) => $q->where('activo', true))
                ->with(['cliente:id,nombre', 'ruta:id,nombre'])
                ->orderBy('nombre')
                ->paginate(self::SALAS_POR_PAGINA)
                ->withQueryString();
        }

        return view('rutas.rutas.show', [
            'ruta' => $ruta,
            'asignadas' => $asignadas,
            'candidatas' => $candidatas,
            'busco' => $busco,
            'clientes' => Cliente::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function edit(Ruta $ruta): View
    {
        return view('rutas.rutas.edit', ['ruta' => $ruta]);
    }

    public function update(RutaRequest $request, Ruta $ruta): RedirectResponse
    {
        $ruta->update($request->validated());

        return redirect()
            ->route('rutas.rutas.show', $ruta)
            ->with('status', "Ruta «{$ruta->nombre}» actualizada.");
    }

    public function toggleActiva(Ruta $ruta): RedirectResponse
    {
        $ruta->update(['activa' => ! $ruta->activa]);

        return back()->with(
            'status',
            $ruta->activa
                ? "Ruta «{$ruta->nombre}» activada."
                : "Ruta «{$ruta->nombre}» desactivada. Sus salas y su historial se conservan."
        );
    }
}
