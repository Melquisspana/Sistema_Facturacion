<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Services\Rutas\MapaVentas;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * La pestaña «Mapa» del tablero de Rutas ({@see MapaVentas}). Solo lectura. La ruta exige
 * `rutas.ver` y `rutas.mapa.ver`; el mes y la ruta llegan por GET y se validan acá. Los
 * modos (ventas, dónde falta, rutas) y el nivel (departamentos o distritos) cambian en el
 * navegador, sin volver a pedir la página.
 */
class MapaVentasController extends Controller
{
    public function index(Request $request, MapaVentas $mapa): View
    {
        $meses = MapaVentas::meses();
        $filtro = $request->validate([
            'mes' => ['nullable', 'string', Rule::in(array_keys($meses))],
            'ruta' => ['nullable', 'integer', 'min:1', Rule::exists('rutas', 'id')->where('activa', true)],
        ], [
            'mes.*' => 'Elegí un mes de la lista.',
            'ruta.*' => 'Elegí una ruta activa de la lista.',
        ]);
        $mes = $filtro['mes'] ?? array_key_first($meses);
        $ruta = isset($filtro['ruta']) ? (int) $filtro['ruta'] : null;

        // Archivos estáticos con su fecha: al desplegar uno nuevo, el navegador no usa el viejo.
        $version = fn (string $archivo) => asset($archivo).'?v='.(is_file(public_path($archivo)) ? filemtime(public_path($archivo)) : '1');

        return view('rutas.mapa', [
            'datos' => $mapa->calcular($mes, $ruta) + [
                'ruta' => $ruta,
                'geo' => ['departamentos' => $version('geo/departamentos.json'), 'distritos' => $version('geo/distritos.json')],
            ],
            'meses' => $meses,
            'filtro' => ['mes' => $mes, 'ruta' => $ruta],
            'hayFiltro' => $mes !== array_key_first($meses) || $ruta !== null,
            'script' => $version('js/rutas-mapa.js'),
        ]);
    }
}
