<?php

use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cabeceras de seguridad en todas las respuestas web.
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // Confiar en el proxy local (Tailscale Serve -> 127.0.0.1:80) para
        // interpretar correctamente X-Forwarded-Proto/Host/Port/For.
        $middleware->trustProxies(
            at: '127.0.0.1',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // Alias de middleware de roles/permisos (spatie/laravel-permission).
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Punto de venta predeterminado mal configurado (código inexistente/inactivo):
        // puede lanzarse ANTES de que corra el código del controller (CrearBorradorRequest
        // ::prepareForValidation()), así que se maneja acá para dar un mensaje claro en vez
        // de un 500 genérico. Nunca es un error del usuario: es de configuración del sistema.
        $exceptions->render(function (PuntoVentaPredeterminadoInvalidoException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

            return back()->withErrors(['punto_venta_id' => $e->getMessage()]);
        });
    })->create();
