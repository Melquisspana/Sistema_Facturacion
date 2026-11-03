<?php

/*
|--------------------------------------------------------------------------
| Avisos por correo cuando algo falla (issue #163)
|--------------------------------------------------------------------------
| `alertas:revisar` corre cada 5 minutos desde el planificador, mira lo que puede
| estar mal y escribe solo cuando algo se rompe, cuando sigue roto y empeoró, y
| cuando se normaliza. Apagado de fábrica: sin ALERTAS_ENABLED=true no revisa ni
| manda nada.
|
| El correo sale por el SMTP de Configuración › Correo y, fuera de producción, se
| registra como simulado (App\Support\Correo\CandadoCorreoReal).
*/

return [

    'enabled' => (bool) env('ALERTAS_ENABLED', false),

    // Uno o varios correos separados por coma. Va en el .env de cada servidor, nunca
    // en el código.
    'correo' => env('ALERTAS_CORREO', ''),

    // Mientras una falla sigue abierta, se vuelve a avisar solo si empeoró y pasó este
    // tiempo desde el último correo de ese tipo.
    'reaviso_minutos' => (int) env('ALERTAS_REAVISO_MINUTOS', 60),

    // Tope de correos por tipo y por día (falla, recordatorio y normalizado cuentan).
    // Corta una falla que se abre y se cierra sin parar.
    'tope_diario' => (int) env('ALERTAS_TOPE_DIARIO', 6),

    // Errores 500 y tareas programadas se anotan cuando pasan; se dan por normalizados
    // cuando pasa este tiempo sin uno nuevo.
    'ventana_eventos_minutos' => 30,

    // Lector de asistencia: cuánto puede estar callado en horario laboral.
    'lector' => [
        'sin_ping_minutos' => (int) env('ALERTAS_LECTOR_SIN_PING_MINUTOS', 15),
        // Día ISO (1 = lunes) => [desde, hasta], hora de El Salvador.
        'horario' => [
            1 => ['08:00', '17:00'],
            2 => ['08:00', '17:00'],
            3 => ['08:00', '17:00'],
            4 => ['08:00', '17:00'],
            5 => ['08:00', '17:00'],
            6 => ['08:00', '12:00'],
        ],
    ],

    // Si a esta hora no hay un respaldo válido de hoy, se avisa.
    'respaldo_hora_limite' => env('ALERTAS_RESPALDO_HORA_LIMITE', '06:00'),

    /*
    | Ruta pública /salud, para el vigilante de afuera.
    */
    'salud' => [
        // URL de estado de cada firmador, separadas por coma. Vacío: se revisa solo el
        // firmador de config/dte.php, y solo si la firma real está encendida.
        'firmadores' => env('SALUD_FIRMADORES', ''),
        'disco_minimo_mb' => (int) env('SALUD_DISCO_MINIMO_MB', 2048),
        'desfase_hora_segundos' => 120,
        // Un trabajo esperando más que esto en la cola cuenta como cola trabada.
        'cola_trabada_minutos' => 10,
        // Sin una revisión de avisos en este tiempo, el planificador está parado.
        'avisos_sin_correr_minutos' => 15,
    ],

];
