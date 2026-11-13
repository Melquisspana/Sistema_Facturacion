<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Services\Rutas\MapaVentas;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * La pestaña «Mapa» del tablero de Rutas ({@see MapaVentas}). Solo lectura. La ruta exige
 * `rutas.ver` y `rutas.mapa.ver`; el mes y la zona llegan por GET y se validan acá. La vista
 * (ventas o a dónde ir) y el departamento abierto cambian en el navegador, sin volver a
 * pedir la página.
 *
 * El jefe de vendedores ve las mismas estadísticas sin plata: cuántas facturas en vez de
 * cuánto se vendió (Melqui, 12/11/2026). Va por rol, no por un permiso nuevo, porque un
 * permiso nuevo habría que crearlo a mano en producción. El servidor no le manda montos.
 */
class MapaVentasController extends Controller
{
    public function index(Request $request, MapaVentas $mapa): Response
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

        $datos = $mapa->calcular($mes, $ruta, soloFacturas: $request->user()->hasRole(RolSistema::JefeVendedores->value));

        $html = view('rutas.mapa', [
            'datos' => $datos + [
                'geo' => ['departamentos' => $version('geo/departamentos.json'), 'distritos' => $version('geo/distritos.json')],
            ],
            'listas' => $mapa->listas($datos),
            'meses' => $meses,
            'filtro' => ['mes' => $mes, 'ruta' => $ruta],
            'hayFiltro' => $mes !== array_key_first($meses) || $ruta !== null,
            'script' => $version('js/rutas-mapa.js'),
        ])->render();

        // Sin la sangría de las plantillas (12/11/2026): con las listas de cada departamento y
        // la tabla, casi la mitad de la página eran espacios al comienzo de los renglones. El
        // navegador no los muestra (la página no tiene <pre> ni <textarea>) y en el celular
        // pesaban.
        // Si la expresión fallara (devuelve null), la página sale tal cual, nunca en blanco.
        return response(preg_replace('/^[ \t]+/m', '', $html) ?? $html);
    }
}
