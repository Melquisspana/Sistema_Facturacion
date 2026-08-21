<?php

/*
| Control de Asistencia — módulo de marcaciones con lector de huella físico.
|
| Qué es: un dispositivo ESP32 con sensor de huella AS608 lee una huella, la
| resuelve a un «fingerprint ID» y se lo manda a este servidor por HTTP. Laravel
| es quien decide de QUIÉN es esa huella y a QUÉ hora se marcó. El dispositivo no
| guarda nombres de empleados ni su propia hora oficial: solo muestra lo que el
| servidor le responde.
|
| Estado HOY: diagnóstico (/api/asistencia/ping) y marcación real
| (/api/asistencia/marcar), con empleados, huellas, dispositivos y marcaciones en
| base de datos. Todavía NO hay horarios, tardanzas, reportes ni planilla, y NO
| hay pantallas de administración: se administra con los comandos `asistencia:*`.
|
| El módulo NO toca DTE, facturación, PPQ, notas de crédito, invalidaciones,
| exportaciones, Rutas/Cobros ni Planta.
*/
return [
    /*
    | Interruptor del MÓDULO COMPLETO, igual que config/planta.php. Apagado, toda
    | ruta de /api/asistencia responde 404 (no 403: un 403 confirmaría que el
    | endpoint existe). Las rutas se registran SIEMPRE; quien corta es el
    | middleware `modulo.asistencia`.
    |
    | Arranca APAGADO a propósito: son endpoints que un dispositivo consume SIN
    | sesión de usuario, así que en un servidor donde el módulo no se usa no deben
    | ni existir. Se enciende con ASISTENCIA_ENABLED=true en el .env de la máquina
    | que tiene el lector.
    |
    | No tiene ninguna relación con el ambiente MH ni con APP_ENV.
    */
    'enabled' => (bool) env('ASISTENCIA_ENABLED', false),

    /*
    | Zona horaria OFICIAL de las marcaciones. La hora de una marcación la pone
    | SIEMPRE el servidor —nunca el reloj del ESP32, que no tiene batería, se
    | reinicia y puede quedar desfasado—, y esta es la zona con la que se le
    | presenta al dispositivo para mostrarla en pantalla.
    |
    | Existe aparte de `config('app.timezone')` porque esa está en UTC (ver
    | config/app.php) y toda la aplicación guarda en UTC: cambiarla movería fechas
    | de DTE, correlativos y reportes. Acá solo se decide cómo se FORMATEA la hora
    | para el operador que está parado frente al lector.
    */
    'zona_horaria' => env('ASISTENCIA_ZONA_HORARIA', 'America/El_Salvador'),

    /*
    | VENTANA DE CORTESÍA contra la doble marcación accidental. Segundos que deben
    | pasar entre dos marcaciones DE LA MISMA PERSONA para que la segunda cuente.
    |
    | El problema real que resuelve: el sensor reconoce en menos de un segundo y la
    | gente deja el dedo puesto, lo vuelve a poner porque no vio la pantalla, o el
    | firmware reintenta. Sin esta ventana, «primera marcación = entrada, siguiente
    | = salida» convierte un dedo repetido en una jornada de dos segundos.
    |
    | Dentro de la ventana NO se escribe nada y NO se devuelve un error: se
    | responde qué se marcó antes y cuánto falta, que es lo que la persona parada
    | frente al lector necesita ver.
    |
    | 90 segundos es el valor por defecto. Es holgado a propósito: nadie entra y
    | sale legítimamente en minuto y medio, y equivocarse por corto (una entrada
    | perdida) cuesta más que equivocarse por largo (esperar un momento).
    */
    'cooldown_segundos' => (int) env('ASISTENCIA_COOLDOWN_SEGUNDOS', 90),

    /*
    | Token de PROVISIÓN de un lector. Lo lee EXCLUSIVAMENTE el comando
    | `php artisan asistencia:dispositivo`, para poder fijar desde el .env el token
    | que se va a quemar en el firmware en vez de tener que copiar uno generado al
    | azar. Si está vacío, el comando genera uno y lo muestra una sola vez.
    |
    | NUNCA se lee al autenticar una petición. Esa diferencia es la que importa:
    | quien autentica es SIEMPRE la fila de `asistencia_dispositivos` con su hash,
    | así que un lector se revoca solo, sin tocar el .env ni a los demás, y borrar
    | esta variable después de dar de alta el dispositivo no rompe nada.
    |
    | Se puede (y conviene) dejar vacía en producción.
    */
    'token_provision' => env('ASISTENCIA_DISPOSITIVO_TOKEN'),
];
