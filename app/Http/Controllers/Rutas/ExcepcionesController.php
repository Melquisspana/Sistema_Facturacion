<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\Ruta;
use App\Services\Rutas\BandejaDocumentos;
use App\Services\Rutas\BandejaExcepciones;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bandeja de excepciones: lo que no cuadra, agrupado por motivo.
 *
 * Pantalla de SOLO LECTURA. Cada fila enlaza a donde el problema se resuelve —el detalle de
 * la salida, la pantalla de recepción, la ficha de la persona—; acá no se arregla nada,
 * porque cada acto necesita su contexto y su permiso.
 *
 * ─────────────────────── Una sola fuente, como el dashboard ───────────────────────
 *
 * Llama UNA vez a {@see BandejaDocumentos::consultar()} —la misma función, con los mismos
 * filtros y la misma ventana que la bandeja— y le pasa el resultado a
 * {@see BandejaExcepciones} para que lo clasifique. No hay una segunda consulta ni una regla
 * reescrita acá: es lo que hace imposible que esta pantalla contradiga al listado que abre.
 */
class ExcepcionesController extends Controller
{
    /** Solo los filtros DUROS: los derivados los aplica la propia clasificación. */
    private const FILTROS = ['desde', 'hasta', 'ruta_id', 'sucursal_id', 'salida_id'];

    public function index(Request $request, BandejaDocumentos $bandeja, BandejaExcepciones $excepciones): View
    {
        $filtros = $request->only(self::FILTROS);

        ['documentos' => $documentos, 'desde' => $desde, 'hasta' => $hasta] = $bandeja->consultar($filtros);

        $grupos = $excepciones->clasificar($documentos);

        return view('rutas.excepciones.index', [
            'grupos' => $grupos,
            'conteos' => $excepciones->contar($grupos),
            'catalogo' => BandejaExcepciones::CATALOGO,
            'total' => array_sum($excepciones->contar($grupos)),
            'documentosRevisados' => $documentos->count(),
            'desde' => $desde,
            'hasta' => $hasta,
            'filtros' => $filtros,
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
