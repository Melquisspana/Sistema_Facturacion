<?php

namespace App\Http\Middleware;

use App\Services\Gastos\InstalacionGastos;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candado de las funciones que necesitan el esquema de FASE 2 —recurrencias y
 * avisos—. Sin esas tablas, estas pantallas responden 503 con una explicación en vez
 * de reventar con un error de SQL.
 *
 * Por qué 503 y no 404. El 404 de {@see ModuloGastosActivo} significa «este módulo no
 * existe para vos», que es una DECISIÓN tomada con el interruptor. Acá el módulo está
 * encendido y la función existe: lo que falta es correr las migraciones. Son cosas
 * distintas y conviene que se distingan, porque la acción a tomar es distinta:
 * ante el 404 hay que encender el módulo; ante el 503, migrar. Un 404 acá mandaría a
 * quien administra a buscar un interruptor que ya está encendido.
 *
 * Se registra en la petición para que quede rastro: si alguien reporta que
 * «Recurrentes no abre», el log dice exactamente qué tabla falta.
 */
class ModuloGastosFase2
{
    public function handle(Request $request, Closure $next): Response
    {
        $instalacion = app(InstalacionGastos::class);

        if (! $instalacion->fase2Instalada()) {
            $faltan = $instalacion->tablasQueFaltan();

            Log::warning('Gastos fase 2 sin instalar', [
                'ruta' => $request->path(),
                'tablas_faltantes' => $faltan,
            ]);

            // Pantalla propia y NO `abort(503)`. La página de error 503 de Laravel la
            // comparte el modo mantenimiento —pisarla cambiaría el mensaje de todo el
            // sistema— y además no muestra el motivo: quien la ve lee «Service
            // Unavailable» y se queda sin saber que lo que falta es correr migraciones.
            return response()->view('gastos.fase2-sin-instalar', [
                'tablasQueFaltan' => $faltan,
                'motivo' => $instalacion->motivoNoInstalada(),
            ], 503);
        }

        return $next($request);
    }
}
