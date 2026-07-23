<?php

return [

    /*
    | Días de retención de los dumps AUTOMÁTICOS (prefijo "auto-") en backups/.
    | NUNCA se borran archivos sin ese prefijo (protege los dumps manuales que ya
    | existen en esa carpeta). Configurable; mínimo recomendado 14-30 días.
    */
    'dias_retencion' => (int) env('BACKUP_DIARIO_DIAS_RETENCION', 30),

    /*
    | Prefijo de los archivos generados por el comando automático/manual. Solo los
    | archivos con este prefijo son candidatos a borrarse por retención.
    */
    'prefijo_automatico' => 'auto-',

    /*
    | Reintentos automáticos si mysqldump falla (exit code != 0 o excepción al
    | lanzarlo). Cubre el fallo INTERMITENTE del cliente MySQL "Can't create TCP/IP
    | socket" cuando el proceso se lanza desde Apache: el mismo comando funciona
    | segundos después. El dump es solo lectura; reintentar es seguro.
    */
    'reintentos' => (int) env('BACKUP_DIARIO_REINTENTOS', 2),
    'reintentos_espera_segundos' => (int) env('BACKUP_DIARIO_REINTENTOS_ESPERA', 3),
];
