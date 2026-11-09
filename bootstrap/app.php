<?php

use App\Ajustes\Excepciones\AlmacenAjustesNoDisponibleException;
use App\Exceptions\Dte\PuntoVentaPredeterminadoInvalidoException;
use App\Http\Controllers\SaludController;
use App\Http\Middleware\AutenticaDispositivoAsistencia;
use App\Http\Middleware\CerrarSesionUsuarioInactivo;
use App\Http\Middleware\CloudflareAccessSso;
use App\Http\Middleware\ModuloAsistenciaActivo;
use App\Http\Middleware\ModuloGastosActivo;
use App\Http\Middleware\ModuloGastosFase2;
use App\Http\Middleware\ModuloPlanillaActivo;
use App\Http\Middleware\ModuloPlantaActivo;
use App\Http\Middleware\RedirigirAreaPrincipal;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Alertas\RegistroEventos;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Endpoints para DISPOSITIVOS (hoy: el lector de huella del control de
        // asistencia). Van bajo /api con el grupo `api`: sin sesión y sin CSRF,
        // que es lo que un ESP32 puede cumplir. No cambia ninguna ruta web.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // /salud, para el vigilante de afuera (issue #163). Fuera del grupo `web`: sin
        // sesión, sin cookies y sin el SSO de Cloudflare Access. Con límite de pedidos,
        // porque cada consulta pregunta en vivo a los firmadores.
        then: function () {
            Route::get('/salud', SaludController::class)->middleware('throttle:30,1')->name('salud');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cabeceras de seguridad en todas las respuestas web + SSO de Cloudflare
        // Access (no-op salvo dominio público con SSO habilitado por config; el
        // login local de facturacion.test/localhost/Tailscale no se toca).
        $middleware->web(append: [
            SecurityHeaders::class,
            CloudflareAccessSso::class,
            // Un usuario desactivado sale en la petición siguiente, no al vencer su sesión.
            CerrarSesionUsuarioInactivo::class,
        ]);

        // Cabeceras X-Forwarded-* que se creen, y solo si vienen de un proxy de confianza.
        // La lista de proxies no se fija acá: este bloque corre antes de cargar la
        // configuración. La lee el middleware en cada pedido desde config/trustedproxy.php
        // (TRUSTED_PROXIES; por defecto 127.0.0.1, Tailscale Serve en Windows).
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // Alias de middleware de roles/permisos (spatie/laravel-permission) y de
        // áreas del sistema (ver App\Enums\AreaSistema):
        //  - modulo.planta   : 404 si el módulo Producción/Planta está apagado.
        //  - modulo.asistencia: 404 si el módulo Control de Asistencia está apagado.
        //  - modulo.gastos   : 404 si el módulo Gastos está apagado; 403 si el
        //                      usuario está desactivado.
        //  - modulo.planilla : 404 si Planilla está apagado; 503 si faltan sus
        //                      migraciones. Salarios: exige usuario activo.
        //  - modulo.gastos.fase2 : 503 si faltan las migraciones de recurrencias y
        //                      avisos. Es OTRA pregunta que «apagado»: el módulo está
        //                      encendido y lo que falta es migrar.
        //  - dispositivo.asistencia: 401 sin token válido de lector biométrico.
        //  - area.principal  : aterrizaje por área; se usa SOLO en /dashboard.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'modulo.planta' => ModuloPlantaActivo::class,
            'modulo.asistencia' => ModuloAsistenciaActivo::class,
            'modulo.gastos' => ModuloGastosActivo::class,
            'modulo.gastos.fase2' => ModuloGastosFase2::class,
            'modulo.planilla' => ModuloPlanillaActivo::class,
            'dispositivo.asistencia' => AutenticaDispositivoAsistencia::class,
            'area.principal' => RedirigirAreaPrincipal::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Cada respuesta 500 se anota para los avisos por correo (issue #163). Solo se
        // anota: el correo lo decide `alertas:revisar`, agrupado y con tope.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 500) {
                RegistroEventos::anotar(RegistroEventos::ERRORES_500,
                    ($request->route()?->getName() ?? 'sin nombre de ruta').' · '.class_basename($e));
            }

            return $response;
        });

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

        // Guardar configuración durante la ventana de un despliegue a medias (código
        // nuevo, `migrate` todavía sin correr). Leer en esa ventana funciona; escribir
        // no tiene dónde hacerlo. Se responde con el mensaje de la excepción —que dice
        // qué falta y que no se perdió nada— en vez de un 500 con la excepción de SQL,
        // que además llevaría dentro el nombre de la base y del host.
        $exceptions->render(function (AlmacenAjustesNoDisponibleException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            return back()->withErrors(['configuracion' => $e->getMessage()])->withInput();
        });
    })->create();
