<?php

/*
| Documentos recibidos — CCF/facturas que LLEGAN por correo (somos receptor).
|
| Fuente de correo INDEPENDIENTE de Gmail/PPQ. Los CCF de proveedores llegan al
| buzón Yahoo (dulceslanegrita@yahoo.com) por IMAP. Solo lectura: el lector NO
| borra, NO mueve y NO marca como leído. Sin configuración/credenciales, el módulo
| igual funciona mostrando lo ya guardado; la revisión queda deshabilitada.
|
| Los VALORES (host/usuario/contraseña) viven solo en .env, nunca en el repo.
*/
return [
    // Driver de la fuente de correo: 'imap' (real) o 'none' (sin fuente configurada).
    'mail' => [
        'driver' => env('DOCUMENTOS_RECIBIDOS_MAIL_DRIVER', 'imap'),
        'host' => env('DOCUMENTOS_RECIBIDOS_MAIL_HOST', ''),
        'port' => (int) env('DOCUMENTOS_RECIBIDOS_MAIL_PORT', 993),
        'encryption' => env('DOCUMENTOS_RECIBIDOS_MAIL_ENCRYPTION', 'ssl'),
        'username' => env('DOCUMENTOS_RECIBIDOS_MAIL_USERNAME', ''),
        'password' => env('DOCUMENTOS_RECIBIDOS_MAIL_PASSWORD', ''),
        'folder' => env('DOCUMENTOS_RECIBIDOS_MAIL_FOLDER', 'INBOX'),
        // Filtro IMAP de búsqueda (por defecto: correos con asunto/fecha recientes).
        // Se acota además por adjuntos en el lector. Vacío = todos los del folder.
        'search' => env('DOCUMENTOS_RECIBIDOS_MAIL_SEARCH', 'ALL'),
        // Timeout de conexión IMAP en segundos.
        'timeout' => (int) env('DOCUMENTOS_RECIBIDOS_MAIL_TIMEOUT', 15),
    ],

    /*
    | INTERRUPTOR de la sincronización automática.
    |
    | APAGADO por defecto, a propósito. La tarea programada existe en routes/console.php
    | desde el despliegue, pero no ejecuta nada hasta que alguien la enciende acá. Así el
    | código puede llegar al servidor antes de que el buzón esté configurado, y antes de
    | recuperar el backlog histórico —que es el orden correcto: si la automática arranca
    | primero, la marca de progreso se establece sobre los últimos días y el backlog queda
    | fuera del barrido incremental—.
    |
    | Vive en .env y no en la base: es una decisión de despliegue, y se lee cuando el
    | scheduler evalúa la tarea, un momento en el que la base puede no estar migrada.
    |
    | Encenderlo NO dispara nada por sí solo: hace falta además que el servidor ejecute
    | `php artisan schedule:run` cada minuto (ver docs/SINCRONIZACION_COMPRAS.md §3).
    */
    'sincronizacion_automatica' => (bool) env('DOCUMENTOS_RECIBIDOS_AUTO_SYNC', false),

    /*
    | TAMAÑO DE PÁGINA: cuántos correos se le piden al buzón en cada petición.
    |
    | NO es el máximo que se sincroniza. El recorrido agota cada día paginando por UID
    | ascendente, así que este número decide en cuántas peticiones se lee un día, no
    | cuántos correos entran. Antes SÍ era un tope —«máximo de correos por sincronización»—
    | y era exactamente la causa de que se perdieran correos: lo que quedaba por debajo
    | del corte no se leía nunca, y la marca de progreso le pasaba por encima.
    |
    | La clave se conserva (`limite`, DOCUMENTOS_RECIBIDOS_LIMITE) por compatibilidad con
    | los .env y los ajustes ya guardados; lo que cambió es lo que significa.
    */
    'limite' => (int) env('DOCUMENTOS_RECIBIDOS_LIMITE', 30),

    // Carpeta local (disco 'local') donde se guardan los adjuntos descargados para
    // el futuro envío a contabilidad. No se sube nada ni se reenvía en esta fase.
    'storage_dir' => env('DOCUMENTOS_RECIBIDOS_STORAGE_DIR', 'documentos-recibidos'),

    // Límite TOTAL de adjuntos por correo individual a contabilidad (15 MB). Los
    // archivos se agregan por prioridad (PDF → JSON → otros) mientras quepan; lo que
    // no cabe se reporta como omitido y NUNCA hace fallar el envío completo. No se
    // parte ni se comprime nada automáticamente.
    'adjuntos_max_bytes' => 15 * 1024 * 1024,

    /*
    | Exclusión de correos NO-DTE durante la sincronización.
    |
    | Evita crear registros para correos que claramente no son un DTE (estados de
    | cuenta bancarios, órdenes de compra, PDF-only sin DTE). Se evalúa DESPUÉS de
    | clasificar y ANTES de guardar adjuntos o crear el registro.
    |
    | GARANTÍA: un correo con JSON DTE válido (tipoDte reconocible) NUNCA se descarta,
    | venga de quien venga; solo actúa sobre la clasificación `no_es_dte`. Por eso
    | `dte_valido`, `tipo_no_soportado`, `json_invalido` y `falta_adjunto` se conservan.
    | Las reglas específicas se comparan por ASUNTO / NOMBRE DE ADJUNTO normalizados
    | (minúsculas, sin acentos, sin espacios), NUNCA solo por remitente.
    |
    | Configurado aquí (activo por defecto), sin variables nuevas de entorno.
    */
    'exclusiones' => [
        'activo' => true,

        // Reglas específicas por asunto / nombre de adjunto (solo aplican a no-DTE).
        'reglas' => [
            ['nombre' => 'estado_de_cuenta', 'asunto' => ['estado de cuenta'], 'adjunto' => []],
            ['nombre' => 'orden_de_compra',  'asunto' => ['orden de compra'],  'adjunto' => []],
        ],

        // Descarte general de cualquier correo `no_es_dte` (sin JSON DTE válido y que
        // no parece DTE). Con esto activo, estado de cuenta / OC ya caen aquí también;
        // las reglas específicas solo aportan un motivo nombrado en el log.
        'descartar_no_dte' => true,
    ],
];
