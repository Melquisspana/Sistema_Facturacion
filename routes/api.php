<?php

use App\Http\Controllers\Asistencia\DispositivoMarcacionController;
use App\Http\Controllers\Asistencia\DispositivoPingController;
use App\Http\Controllers\Asistencia\EnrolamientoDispositivoController;
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
|   2. throttle:asistencia-dispositivo -> presupuesto POR LECTOR (120/min) más un
|                           techo por IP (300/min). Antes era `throttle:60,1`, que
|                           reparte por IP: con tres lectores detrás del mismo NAT
|                           compartían 60 entre todos, y el sondeo del enrolamiento
|                           habría dejado sin cuota a las marcaciones reales. Ver
|                           AppServiceProvider.
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
    ->middleware(['modulo.asistencia', 'throttle:asistencia-dispositivo'])
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

        /*
        | ─────────────────────────── ENROLAMIENTO ───────────────────────────
        |
        | El buzón que permite que el servidor le pida algo al lector sin poder
        | llamarlo. El ESP32 SONDEA; Laravel nunca inicia la conexión.
        |
        | Se eligió sondeo y no empuje por tres motivos, en orden de peso:
        |   1. empujar exigiría una credencial en dirección contraria —sin ella,
        |      cualquiera en la LAN dispararía enrolamientos—;
        |   2. la IP del lector la reparte el DHCP y `ultima_ip` envejece;
        |   3. el sondeo sobrevive al NAT y al día que este servidor viva en otro
        |      sitio.
        | El precio es la latencia, y enrolar es un acto supervisado: 3 s no se notan.
        |
        | Los CUATRO exigen token de lector. Un navegador no puede tocarlos: no
        | conoce el token y la web nunca lo muestra.
        */
        Route::middleware('dispositivo.asistencia')->prefix('enrolamiento')->name('enrolamiento.')->group(function () {
            // El sondeo. Entrega la orden y su token —la ÚNICA vez que ese valor
            // sale del servidor—. Es GET porque no crea nada: mueve una orden que
            // ya existía de `pendiente` a `tomada`.
            Route::get('pendiente', [EnrolamientoDispositivoController::class, 'pendiente'])->name('pendiente');

            // Progreso: informativo. No puede completar ni fallar una orden.
            Route::post('{orden}/progreso', [EnrolamientoDispositivoController::class, 'progreso'])->name('progreso');

            // El acto. IDEMPOTENTE: el lector puede grabar, mandar esto y perder la
            // respuesta; al reintentar obtiene el mismo desenlace, no una segunda
            // asignación.
            Route::post('{orden}/resultado', [EnrolamientoDispositivoController::class, 'resultado'])->name('resultado');

            // Lo que el AS608 dice de sí mismo. Sin esto el servidor elige ranura a
            // ciegas y choca con las plantillas heredadas.
            Route::post('indice-sensor', [EnrolamientoDispositivoController::class, 'indiceSensor'])->name('indice-sensor');
        });
    });
