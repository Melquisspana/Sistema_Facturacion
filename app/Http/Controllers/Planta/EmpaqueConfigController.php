<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Enums\Planta\TipoInsumo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planta\EmpaqueConfigRequest;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use App\Services\Planta\EmpaqueConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Configuraciones de empaque: qué bolsa y qué viñeta lleva una presentación
 * para un mercado y una marca.
 *
 * TODA escritura pasa por {@see EmpaqueConfigService}. El controlador no valida
 * reglas de dominio ni toca la base directamente: solo autoriza, delega y
 * traduce el resultado a una redirección.
 */
class EmpaqueConfigController extends Controller
{
    private const GESTIONAR = 'planta.catalogos.gestionar';

    public function __construct(private readonly EmpaqueConfigService $servicio) {}

    public function index(Request $request): View
    {
        $configs = PlantaEmpaqueConfig::query()
            ->with(['presentacion:id,codigo,nombre,planta_producto_base_id', 'presentacion.productoBase:id,nombre', 'bolsa:id,codigo,nombre', 'vinieta:id,codigo,nombre'])
            ->when($request->filled('presentacion'), fn ($q) => $q->where('planta_presentacion_id', $request->integer('presentacion')))
            ->when($request->filled('producto_base'), fn ($q) => $q->whereHas('presentacion', fn ($p) => $p->where('planta_producto_base_id', $request->integer('producto_base'))))
            ->when($request->filled('mercado'), fn ($q) => $q->where('mercado', $request->string('mercado')))
            ->when($request->filled('marca'), fn ($q) => $q->where('marca_norm', 'like', '%'.mb_strtoupper(trim((string) $request->string('marca'))).'%'))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->boolean('activo')))
            ->when($request->filled('predeterminada'), fn ($q) => $q->where('es_predeterminada', $request->boolean('predeterminada')))
            ->orderByDesc('es_predeterminada')
            ->orderBy('planta_presentacion_id')
            ->paginate(25)
            ->withQueryString();

        return view('planta.empaques.index', [
            'configs' => $configs,
            'presentaciones' => PlantaPresentacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'productosBase' => PlantaProductoBase::orderBy('nombre')->get(['id', 'nombre']),
            'mercados' => MercadoPlanta::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->autorizarGestion($request);

        return view('planta.empaques.create', $this->opcionesFormulario());
    }

    public function store(EmpaqueConfigRequest $request): RedirectResponse
    {
        $this->autorizarGestion($request);

        // El servicio lanza ValidationException si algún invariante falla; el
        // manejador de Laravel la convierte en redirect con errores.
        $config = $this->servicio->crear($request->validated());

        return redirect()
            ->route('planta.empaques.index')
            ->with('status', "Configuración de empaque #{$config->id} creada.");
    }

    public function edit(Request $request, PlantaEmpaqueConfig $empaque): View
    {
        $this->autorizarGestion($request);

        return view('planta.empaques.edit', $this->opcionesFormulario($empaque) + ['config' => $empaque]);
    }

    public function update(EmpaqueConfigRequest $request, PlantaEmpaqueConfig $empaque): RedirectResponse
    {
        $this->autorizarGestion($request);

        $this->servicio->actualizar($empaque, $request->validated());

        return redirect()
            ->route('planta.empaques.index')
            ->with('status', "Configuración de empaque #{$empaque->id} actualizada.");
    }

    /**
     * Marca esta configuración como la predeterminada de su presentación y
     * mercado. Va por el servicio, que quita la condición a la anterior dentro
     * de la misma transacción.
     */
    public function marcarPredeterminada(Request $request, PlantaEmpaqueConfig $empaque): RedirectResponse
    {
        $this->autorizarGestion($request);

        $this->servicio->marcarPredeterminada($empaque);

        return back()->with('status', "Configuración #{$empaque->id} marcada como predeterminada.");
    }

    public function toggleActivo(Request $request, PlantaEmpaqueConfig $empaque): RedirectResponse
    {
        $this->autorizarGestion($request);

        $this->servicio->alternarActivo($empaque);

        return back()->with(
            'status',
            $empaque->activo
                ? "Configuración #{$empaque->id} activada."
                : "Configuración #{$empaque->id} desactivada."
        );
    }

    /**
     * Opciones de los selectores. Solo insumos ACTIVOS y del tipo correcto; al
     * editar se añade además el insumo histórico aunque haya quedado inactivo,
     * para poder abrir y guardar la ficha sin cambiarlo.
     *
     * @return array<string, mixed>
     */
    private function opcionesFormulario(?PlantaEmpaqueConfig $config = null): array
    {
        return [
            'presentaciones' => PlantaPresentacion::query()
                ->where(fn ($q) => $q->where('activo', true)
                    ->when($config?->planta_presentacion_id, fn ($w, $id) => $w->orWhere('id', $id)))
                ->with('productoBase:id,nombre')
                ->orderBy('nombre')
                ->get(),
            'bolsas' => $this->insumosDe(TipoInsumo::Bolsa, $config?->planta_insumo_bolsa_id),
            'vinietas' => $this->insumosDe(TipoInsumo::Vinieta, $config?->planta_insumo_vinieta_id),
            'mercados' => MercadoPlanta::cases(),
        ];
    }

    private function insumosDe(TipoInsumo $tipo, ?int $incluirId)
    {
        return PlantaInsumo::query()
            ->where('tipo', $tipo->value)
            ->where(fn ($q) => $q->where('activo', true)->when($incluirId, fn ($w) => $w->orWhere('id', $incluirId)))
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'activo']);
    }

    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }
}
