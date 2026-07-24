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

    // Máximo de correos a revisar por sincronización manual.
    'limite' => (int) env('DOCUMENTOS_RECIBIDOS_LIMITE', 30),

    // Carpeta local (disco 'local') donde se guardan los adjuntos descargados para
    // el futuro envío a contabilidad. No se sube nada ni se reenvía en esta fase.
    'storage_dir' => env('DOCUMENTOS_RECIBIDOS_STORAGE_DIR', 'documentos-recibidos'),

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
