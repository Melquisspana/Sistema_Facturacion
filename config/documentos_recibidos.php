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
];
