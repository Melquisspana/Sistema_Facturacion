<?php

namespace App\Http\Controllers\Planta;

use App\Http\Controllers\Controller;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaProveedor;
use App\Services\Planta\PlantaRecepcionService;
use App\Support\Planta\ExistenciaQuery;
use App\Support\Planta\LoteQuery;
use App\Support\Planta\MovimientoQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de lotes: qué entró, cuándo y de quién.
 *
 * LOS LOTES NO SE CREAN NI SE EDITAN DESDE AQUÍ. Nacen en las recepciones
 * ({@see PlantaRecepcionService}), que es el documento que los justifica, y su
 * identidad —insumo, código interno, fechas— es lo que hace trazable el saldo
 * que cuelga de ellos. Por eso este controlador tiene tres métodos y no siete:
 * `index`, `show` y un único cambio de bandera.
 *
 * Cambiar `planta_insumo_id` a un lote con movimientos partiría el bucket: el
 * mayor quedaría con filas cuyo lote apunta a otro insumo y la reconciliación lo
 * vería como una incoherencia que no puede reparar. Cambiar `codigo_interno`
 * rompería la traza legible que enlaza el saco físico con el historial. Ninguna
 * de las dos cosas se corrige editando el lote: se corrige con documentos.
 *
 * EL LOTE GENÉRICO NO EXISTE PARA ESTA PANTALLA. `GEN-<insumo_id>` es un detalle
 * interno del motor de inventario —la quinta dimensión del bucket no admite
 * nulos— y no un lote que alguien haya recibido. {@see LoteQuery} lo excluye del
 * listado siempre, y {@see exigirLoteReal()} devuelve 404 en la ficha y en el
 * cambio de estado. El candado de verdad sigue estando en el modelo, que lanza
 * en `updating` y `deleting`; esto es la capa de superficie.
 */
class LoteController extends Controller
{
    /** Permiso de escritura, reforzado en el controlador además del middleware. */
    private const GESTIONAR = 'planta.catalogos.gestionar';

    /**
     * Cuántos movimientos se muestran en la ficha. Es un resumen, no el
     * historial: para eso está la pantalla de movimientos, ya filtrada por lote.
     */
    private const MOVIMIENTOS_EN_FICHA = 20;

    public function index(Request $request): View
    {
        $consulta = LoteQuery::desdeRequest($request);

        return view('planta.lotes.index', [
            'lotes' => $consulta->paginar(),
            'dias' => $consulta->dias(),
            // Solo los insumos que controlan lotes: el resto no puede tener un
            // lote real y su presencia en el selector solo daría listas vacías.
            'insumos' => PlantaInsumo::query()
                ->where('controla_lotes', true)
                ->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre']),
            'proveedores' => PlantaProveedor::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function show(PlantaLote $lote): View
    {
        $this->exigirLoteReal($lote);

        $lote->load(['insumo:id,codigo,nombre,tipo,unidad_base,controla_lotes', 'proveedor:id,nombre']);

        // Los saldos y sus totales salen de la MISMA consulta filtrada por lote,
        // que es la razón por la que ExistenciaQuery existe: si el detalle y el
        // total se construyeran por separado, podrían dejar de describir lo mismo.
        // Los totales van SEPARADOS por estado: `disponible`, `retenido` y
        // `rechazado` no se suman entre sí ni siquiera dentro de un mismo lote,
        // porque solo el primero puede trasladarse o utilizarse.
        $saldos = new ExistenciaQuery(['lote' => $lote->id]);

        $movimientos = PlantaMovimiento::query()
            ->where('planta_lote_id', $lote->id)
            ->with(['ubicacion:id,codigo,nombre', 'user:id,name'])
            ->orderByDesc('fecha_efectiva')
            ->orderByDesc('id')
            ->limit(self::MOVIMIENTOS_EN_FICHA)
            ->get();

        return view('planta.lotes.show', [
            'lote' => $lote,
            'saldos' => $saldos->paginar(50),
            'totales' => $saldos->totalesPorEstado(),
            'movimientos' => $movimientos,
            // Números de documento resueltos en lote: una consulta por tipo de
            // documento presente, nunca una por fila.
            'numerosDocumento' => MovimientoQuery::numerosDeDocumento($movimientos),
            'totalMovimientos' => PlantaMovimiento::where('planta_lote_id', $lote->id)->count(),
        ]);
    }

    /**
     * Retira el lote de la operación o lo reincorpora. ÚNICA escritura de esta
     * pantalla, y toca UNA sola columna.
     *
     * No borra: un lote con movimientos no puede desaparecer del historial ni
     * siquiera de forma lógica —la tabla no tiene `deleted_at` a propósito y la
     * FK del mayor es `restrictOnDelete`—, así que retirarlo es marcarlo inactivo.
     * El saldo que ya tenga sigue existiendo y sigue contando: esto no mueve
     * inventario, no escribe en el mayor y no toca `planta_existencias`. Lo que
     * cambia es que deja de ofrecerse para entradas nuevas.
     *
     * El rastro lo deja el propio modelo con `LogsActivity`; aquí no se duplica.
     */
    public function toggleActivo(Request $request, PlantaLote $lote): RedirectResponse
    {
        $this->autorizarGestion($request);
        $this->exigirLoteReal($lote);

        $lote->update(['activo' => ! $lote->activo]);

        return back()->with('status', $lote->activo
            ? "Lote «{$lote->codigo_interno}» reincorporado a la operación."
            : "Lote «{$lote->codigo_interno}» retirado de la operación. Su saldo e historial se conservan.");
    }

    /** Defensa en profundidad: el middleware ya filtró, pero no se confía en una sola capa. */
    private function autorizarGestion(Request $request): void
    {
        abort_unless($request->user()?->can(self::GESTIONAR), 403);
    }

    /**
     * 404 para el lote genérico. No es «prohibido» sino «no existe»: para esta
     * interfaz el genérico no es un recurso, igual que no aparece en el listado.
     */
    private function exigirLoteReal(PlantaLote $lote): void
    {
        abort_if($lote->es_generico, 404);
    }
}
