<?php

namespace App\Http\Controllers\Rutas;

use App\Http\Controllers\Controller;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\TableroViajes;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * La pestaña «Viajes» del tablero de Rutas ({@see TableroViajes}): cada viaje abierto y en
 * qué paso va cada pedido, y el PDF de cada día de un viaje. Solo lectura. La ruta exige `rutas.ver`: el jefe de vendedores,
 * Administración, Jefatura y Facturación; el vendedor no la tiene.
 */
class ViajesController extends Controller
{
    public function index(TableroViajes $tablero): View
    {
        return view('rutas.viajes', $tablero->calcular());
    }

    /**
     * El PDF del día de una persona en un viaje, para la oficina y el jefe (`rutas.ver`): el
     * mismo que firma el vendedor al entregar el dinero. De cualquier día del viaje (de que se
     * abrió a que se cerró, nunca futuro) y de quien va en él. Lo demás, 404.
     */
    public function diaPdf(SalidaRuta $salida, string $fecha, PersonalRuta $persona, DiaVendedor $dia): Response
    {
        abort_unless($salida->esViaje(), 404);
        abort_unless($salida->participantes()->where('rutas_personal_id', $persona->id)->exists(), 404);
        [$desde, $hasta] = DiaVendedor::periodo($salida);
        abort_unless(Validator::make(['fecha' => $fecha], [
            'fecha' => ['date_format:Y-m-d', 'after_or_equal:'.$desde, 'before_or_equal:'.$hasta],
        ])->passes(), 404);

        return $dia->pdf($persona, $persona->user, $fecha);
    }
}
