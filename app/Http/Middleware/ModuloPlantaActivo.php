<?php

namespace App\Http\Middleware;

use App\Enums\AreaSistema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor de BACKEND del módulo Producción/Planta. Con PLANTA_ENABLED=false
 * toda ruta del área responde 404, para TODOS los roles incluido administrador.
 *
 * Es 404 y no 403 a propósito: 403 confirmaría que la funcionalidad existe y
 * solo falta permiso. Con el módulo apagado el área simplemente no está.
 *
 * Este middleware es el único candado del interruptor: el selector superior y la
 * sidebar NUNCA autorizan, solo dibujan. Las rutas se registran siempre (aunque
 * el flag esté apagado) para que route('planta.dashboard') no reviente al
 * renderizar una vista compartida; quien bloquea es este middleware, no el
 * registro condicional de rutas.
 */
class ModuloPlantaActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AreaSistema::Planta->habilitada(), 404);

        return $next($request);
    }
}
