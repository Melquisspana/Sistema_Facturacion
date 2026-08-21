<?php

use App\Http\Controllers\Asistencia\DispositivoMarcacionController;
use App\Http\Controllers\Asistencia\DispositivoPingController;
use Illuminate\Support\Facades\Route;

/*
| Rutas HTTP para DISPOSITIVOS (máquina a máquina), servidas bajo el prefijo /api.
|
| Existen aparte de routes/web.php porque un dispositivo no es un navegador: no
| tiene sesión, no tiene cookie, no tiene token CSRF y no puede seguir un redirect
| al login. El grupo `api` de Laravel no monta sesión ni CSRF, así que un POST
| desde el ESP32 funciona sin desactivarle candados a nada del sitio web.
|
| Nada de acá toca DTE, facturación, PPQ, exportaciones, Rutas/Cobros ni Planta.
| Ninguna ruta web existente cambia por este archivo: /api no estaba en uso.
|
| ─────────────────────────── Control de Asistencia ───────────────────────────
|
| Dos rutas, con candados distintos porque hacen cosas distintas.
|
| Comunes a las dos:
|
|   1. modulo.asistencia -> 404 si ASISTENCIA_ENABLED=false. En un servidor que no
|                           tiene lector, estos endpoints no existen.
|   2. throttle:60,1     -> 60 peticiones por minuto y por IP. Un lector real hace
|                           una marcación cada varios segundos; esto solo acota a
|                           quien encuentre la URL en la red local.
|
| Solo en `marcar`:
|
|   3. dispositivo.asistencia -> 401 sin token de lector válido. Es la ruta que
|                           ESCRIBE historial laboral de personas; no puede estar
|                           abierta a cualquiera en la red.
|
| El PING queda a propósito SIN ese tercer candado: es la herramienta para saber
| por qué algo no funciona, y cerrarla haría que un problema de red y uno de
| credenciales se vieran igual. No revela nada y no escribe nada; si se le mandan
| cabeceras de credencial, además dice si son válidas —útil para verificar el token
| del firmware sin generar una marcación de prueba—. Ver el controlador.
*/
Route::prefix('asistencia')
    ->name('api.asistencia.')
    ->middleware(['modulo.asistencia', 'throttle:60,1'])
    ->group(function () {
        // GET y POST a propósito: el firmware definitivo marcará con POST, así que
        // el diagnóstico prueba el mismo verbo que se va a usar de verdad, y GET
        // deja comprobar lo mismo desde un navegador o un curl a secas.
        Route::match(['GET', 'POST'], 'ping', DispositivoPingController::class)->name('ping');

        // La marcación real. Solo POST: es una acción que escribe, y un GET que
        // escriba se dispara con un enlace, con un prefetch o con una recarga.
        Route::post('marcar', DispositivoMarcacionController::class)
            ->middleware('dispositivo.asistencia')
            ->name('marcar');
    });
