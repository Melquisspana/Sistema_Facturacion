<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Services\Rutas\BandejaDocumentos;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Bandeja transversal de documentos: todas las salidas en una sola lista.
 *
 * Pantalla de SOLO LECTURA. No agrega, no quita, no marca papel ni NC —para eso
 * está el detalle de la salida, que es donde el acto tiene contexto—. Por eso se
 * sirve con `rutas.ver` y no exige `rutas.gestionar`.
 *
 * La paginación es en memoria y no por SQL a propósito: parte de los filtros son
 * derivados y se resuelven en PHP ({@see BandejaDocumentos}), así que el total real
 * solo se conoce después de filtrar. Paginar antes daría páginas de tamaño
 * caprichoso y un contador equivocado.
 */
class BandejaDocumentosController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(Request $request, BandejaDocumentos $bandeja): View
    {
        $filtros = $request->only([
            'desde', 'hasta', 'ruta_id', 'salida_id', 'sucursal_id',
            'entrega', 'papel', 'requiere_nc', 'ppq',
        ]);

        ['documentos' => $documentos, 'resumen' => $resumen, 'desde' => $desde, 'hasta' => $hasta] = $bandeja->consultar($filtros);

        $pagina = LengthAwarePaginator::resolveCurrentPage();

        $paginados = new LengthAwarePaginator(
            $documentos->forPage($pagina, self::POR_PAGINA)->values(),
            $documentos->count(),
            self::POR_PAGINA,
            $pagina,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $request->query(),
            ],
        );

        return view('rutas.documentos.index', [
            'documentos' => $paginados,
            'resumen' => $resumen,
            'desde' => $desde,
            'hasta' => $hasta,
            'filtros' => $filtros,
            'rutas' => Ruta::orderBy('nombre')->get(['id', 'nombre']),
            // Solo las salidas de la ventana: un selector con todas las salidas de la
            // historia sería inusable y no serviría para filtrar lo que se está viendo.
            'salidas' => SalidaRuta::with('ruta:id,nombre')
                ->whereBetween('fecha_inicio', [$desde->toDateString(), $hasta->toDateString()])
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'ruta_id', 'fecha_inicio']),
            // Solo salas que pertenecen a alguna ruta: son las que pueden aparecer acá.
            'sucursales' => ClienteSucursal::whereNotNull('ruta_id')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo']),
        ]);
    }
}
