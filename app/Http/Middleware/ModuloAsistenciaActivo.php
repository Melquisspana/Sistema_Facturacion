<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor de BACKEND del módulo Control de Asistencia. Con
 * ASISTENCIA_ENABLED=false toda ruta del módulo responde 404.
 *
 * Es 404 y no 403 por la misma razón que en {@see ModuloPlantaActivo}: un 403
 * confirmaría que el endpoint existe y que solo falta credencial. Con el módulo
 * apagado, para quien toque la URL el módulo sencillamente no está.
 *
 * Este middleware es el único candado del interruptor: las rutas se registran
 * siempre (para que route('api.asistencia.ping') no reviente), quien bloquea es
 * esto y no el registro condicional.
 */
class ModuloAsistenciaActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('asistencia.enabled'), 404);

        return $next($request);
    }
}
