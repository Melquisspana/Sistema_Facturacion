<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Http\Controllers\Controller;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Support\Planta\DocumentoOrigen;
use App\Support\Planta\MovimientoQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Historial del libro mayor: por qué el saldo es el que es.
 *
 * SOLO LECTURA ABSOLUTA. El mayor es append-only —sin `updated_at`, sin
 * `deleted_at`, con el modelo lanzando en `updating` y `deleting`— y esta
 * pantalla ni siquiera podría escribir si quisiera. La ruta solo acepta GET.
 *
 * Es la contrapartida de Existencias: allí se ve el saldo, aquí los hechos que
 * lo produjeron. Cada fila enlaza a su documento de origen a través de un mapa
 * explícito ({@see DocumentoOrigen}); los tipos sin pantalla se muestran como
 * texto y no rompen la tabla.
 */
class MovimientoController extends Controller
{
    public function index(Request $request): View
    {
        $movimientos = MovimientoQuery::desdeRequest($request)->paginar();

        return view('planta.movimientos.index', [
            'movimientos' => $movimientos,
            // Números de documento resueltos en lote: una consulta por tipo de
            // documento presente en la página, nunca una por fila.
            'numerosDocumento' => MovimientoQuery::numerosDeDocumento($movimientos),
            'tiposMovimiento' => TipoMovimientoPlanta::cases(),
            'tiposInsumo' => TipoInsumo::cases(),
            'estados' => EstadoDisponibilidad::cases(),
            'documentos' => DocumentoOrigen::opciones(),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'lotes' => PlantaLote::reales()->orderBy('codigo_interno')->get(['id', 'codigo_interno']),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            // Solo quienes han movido inventario alguna vez: el resto del padrón
            // de usuarios no aporta nada a este filtro y lo haría ilegible.
            'usuarios' => User::query()
                ->whereIn('id', PlantaMovimiento::query()->whereNotNull('user_id')->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
