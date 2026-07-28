<?php

namespace App\Http\Middleware;

use App\Enums\AreaSistema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aterrizaje por área en /dashboard. Se aplica EXCLUSIVAMENTE a esa ruta, que es
 * el punto único al que llegan los siete caminos post-autenticación de Breeze
 * (login, confirmación de contraseña, verificación de correo x3, raíz `/` y el
 * middleware `guest`): todos redirigen a route('dashboard'). Un solo guard aquí
 * cubre todos, sin tocar ningún controlador de Auth/ ni el DashboardController.
 *
 * Correr como middleware —y no como guard dentro del controlador— garantiza por
 * arquitectura que para un usuario ajeno al área Facturación el
 * DashboardController NI SIQUIERA SE INSTANCIA: no se ejecuta una sola consulta
 * sobre dtes, documentos_recibidos, exportaciones, failed_jobs ni jobs.
 *
 * Los cuatro roles históricos tienen `dte.ver`, así que ven el área Facturación y
 * pasan de largo: su /dashboard queda idéntico, con el flag encendido o apagado.
 *
 * Se aplica solo a /dashboard; /planta no lo lleva, así que no hay bucle posible.
 */
class RedirigirAreaPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $visibles = AreaSistema::visiblesPara($usuario);

        // Ve el área Facturación (tiene dte.ver): comportamiento histórico intacto.
        if (in_array(AreaSistema::Facturacion, $visibles, true)) {
            return $next($request);
        }

        // No ve Facturación pero sí otra área habilitada: aterriza en la suya.
        if ($visibles !== []) {
            return redirect()->route($visibles[0]->rutaInicio());
        }

        // No ve ninguna área, pero PERTENECE a una que está apagada (rol
        // produccion con PLANTA_ENABLED=false): el dashboard de Facturación no le
        // corresponde y su módulo no existe todavía. 404, igual que /planta, y
        // sin instanciar el controlador.
        abort_if(AreaSistema::potencialesPara($usuario) !== [], 404);

        // Usuario sin ningún área (p. ej. sin rol asignado): comportamiento
        // histórico. No se le cambia nada al arreglar el caso de arriba.
        return $next($request);
    }
}
