<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Services\Rutas\TableroViajes;
use Illuminate\View\View;

/**
 * La pestaña «Viajes» del tablero de Rutas ({@see TableroViajes}): cada viaje abierto y en
 * qué paso va cada pedido. Solo lectura. La ruta exige `rutas.ver`: el jefe de vendedores,
 * Administración, Jefatura y Facturación; el vendedor no la tiene.
 */
class ViajesController extends Controller
{
    public function index(TableroViajes $tablero): View
    {
        return view('rutas.viajes', $tablero->calcular());
    }
}
