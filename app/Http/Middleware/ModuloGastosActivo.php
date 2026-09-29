<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor de BACKEND del módulo Gastos. Con GASTOS_ENABLED=false toda ruta
 * del módulo responde 404, para TODOS los roles incluido administrador.
 *
 * Es 404 y no 403 por la misma razón que en {@see ModuloPlantaActivo} y
 * {@see ModuloAsistenciaActivo}: un 403 confirmaría que la pantalla existe y que
 * solo falta credencial. Con el módulo apagado, para quien toque la URL el
 * módulo sencillamente no está.
 *
 * Además exige que el usuario esté ACTIVO. Gastos escribe dinero: una cuenta
 * desactivada cuya sesión siga viva no debe poder registrar un pago. El resto de
 * las capas (FormRequest, AccesoGastos, RegistrarPago) repiten la comprobación a
 * propósito; esta es la primera, no la única.
 *
 * Las rutas se registran SIEMPRE, aunque el flag esté apagado, para que
 * route('gastos.create') no reviente al renderizar una vista compartida. Quien
 * bloquea es este middleware, no el registro condicional.
 */
class ModuloGastosActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('gastos.enabled'), 404);
        abort_unless((bool) $request->user()?->activo, 403);

        return $next($request);
    }
}
