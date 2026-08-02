<?php

namespace App\Http\Controllers\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoInsumo;
use App\Http\Controllers\Controller;
use App\Models\Planta\PlantaExistencia;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use App\Support\Planta\ExistenciaQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Qué hay, dónde está y en qué estado.
 *
 * SOLO LECTURA, y no por convención sino por construcción: este controlador
 * tiene un único método, la ruta solo acepta GET y el modelo
 * {@see PlantaExistencia} rechaza cualquier escritura. No hay `store`, `update`
 * ni `destroy` que añadir más adelante: corregir un saldo se hace con un ajuste,
 * que deja documento y motivo, nunca editando esta tabla.
 *
 * La consulta vive en {@see ExistenciaQuery} para que el listado y los totales
 * compartan exactamente los mismos filtros.
 */
class ExistenciaController extends Controller
{
    public function index(Request $request): View
    {
        $consulta = ExistenciaQuery::desdeRequest($request);

        return view('planta.existencias.index', [
            'existencias' => $consulta->paginar(),
            // Totales del conjunto filtrado ENTERO, no de la página, y separados
            // por estado: no existe un total que mezcle disponible con retenido.
            'totales' => $consulta->totalesPorEstado(),
            'estados' => EstadoDisponibilidad::cases(),
            'tipos' => TipoInsumo::cases(),
            'insumos' => PlantaInsumo::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'lotes' => PlantaLote::reales()->orderBy('codigo_interno')->get(['id', 'codigo_interno']),
            'ubicaciones' => PlantaUbicacion::orderBy('nombre')->get(['id', 'codigo', 'nombre']),
        ]);
    }
}
