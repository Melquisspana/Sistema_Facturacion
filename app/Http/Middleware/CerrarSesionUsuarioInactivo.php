<?php

namespace App\Http\Middleware;

use App\Services\Auth\RevocadorSesiones;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario desactivado deja de entrar EN LA PETICIÓN SIGUIENTE, no al vencer su sesión.
 *
 * El login ya rechaza a un inactivo, pero eso solo mira la puerta: quien tenía la sesión
 * abierta —o la cookie de «Recordarme», que dura meses— seguía usando el sistema hasta que
 * la sesión expirara. Gastos y Planilla lo comprobaban por su cuenta; el resto no. Esto lo
 * comprueba en todas las rutas web: cierra la sesión y manda al login.
 *
 * Además, desactivar a alguien revoca sus sesiones guardadas y su token de «Recordarme»
 * ({@see RevocadorSesiones}); este middleware es la segunda línea, la
 * que cubre cualquier camino que cambie `activo` sin pasar por ahí.
 */
class CerrarSesionUsuarioInactivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // Solo un `false` explícito: la columna es NOT NULL con default true, así que un
        // usuario leído de la base nunca trae null (sí un modelo recién creado en memoria).
        if ($usuario === null || $usuario->activo !== false) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sesión cerrada: la cuenta está inactiva.'], 401);
        }

        return redirect()->route('login')
            ->withErrors(['email' => 'Tu sesión se cerró. Si creés que es un error, contactá al administrador.']);
    }
}
