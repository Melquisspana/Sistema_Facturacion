<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Services\Asistencia\NumeroParaMarcar;
use Illuminate\Http\JsonResponse;

/**
 * La lista de números que se lleva el lector para reconocerlos sin red:
 * `{"version", "sal", "numeros": [{"e", "h", "n"}]}`. Solo activos con número y nunca el
 * número en claro ({@see NumeroParaMarcar::paraLector()}).
 *
 * Exige token de lector: con la lista y la sal se prueban los 10.000 números en un momento.
 * El lector la vuelve a pedir cuando `numeros_version` del ping no coincide con la suya.
 */
class DispositivoNumerosController extends Controller
{
    public function __invoke(NumeroParaMarcar $numeros): JsonResponse
    {
        return response()->json($numeros->paraLector());
    }
}
