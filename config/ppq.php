<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Módulo Prontos Pagos (PPQ)
    |--------------------------------------------------------------------------
    | Parámetros operativos. NO toca la emisión de DTE; el módulo solo consulta,
    | vincula y genera el Excel de cobro de Calleja.
    */

    // Sala dentro de la OC: son los `length` dígitos justo DESPUÉS del prefijo YYMM
    // (`prefix` dígitos); conserva el cero inicial. Ej.: 2606026002401 -> 0260.
    'sala_oc_prefix' => (int) env('PPQ_SALA_OC_PREFIX', 4),
    'sala_oc_length' => (int) env('PPQ_SALA_OC_LENGTH', 4),

    // Conciliación CCF/NC vs albarán (en valor absoluto de la diferencia):
    //  - <= coincide  -> "Monto coincide" (tolerancia de redondeo)
    //  - <= pequena   -> "Diferencia pequeña"
    //  - mayor        -> "Posible NC/devolución"
    'diferencia_coincide' => (float) env('PPQ_DIFERENCIA_COINCIDE', 0.05),
    'diferencia_pequena' => (float) env('PPQ_DIFERENCIA_PEQUENA', 1.00),

    // Cliente por defecto del módulo (Calleja). Solo referencia para filtros/UI.
    'cliente_default_id' => env('PPQ_CLIENTE_DEFAULT_ID', null),

    // Código de proveedor que Calleja asigna al emisor (ELSA). Se usa SOLO para nombrar el
    // archivo Excel exportado: {codigo}{YYYYMMDDHHmm}.xlsx (ej. 001065202606300350.xlsx).
    'codigo_proveedor' => env('PPQ_CODIGO_PROVEEDOR', '001065'),

    /*
    |--------------------------------------------------------------------------
    | Integración Gmail (fuente principal de CCF/NC y albaranes)
    |--------------------------------------------------------------------------
    | El sistema lee los CCF/NC de los correos ENVIADOS (facturación en dos
    | sistemas: ContaPortable + propio) y los albaranes del label de Calleja.
    | Credenciales SIEMPRE desde .env, NUNCA en el repo ni en logs.
    */
    'gmail' => [
        'enabled' => (bool) env('PPQ_GMAIL_ENABLED', false),
        'client_id' => env('GMAIL_CLIENT_ID', ''),
        'client_secret' => env('GMAIL_CLIENT_SECRET', ''),
        'redirect_uri' => env('GMAIL_REDIRECT_URI', ''),
        // Búsqueda de los DTE enviados (Gmail query). Se le agrega el nº buscado.
        'enviados_query' => env('PPQ_GMAIL_ENVIADOS_QUERY', 'in:sent'),
        // Filtro de adjunto de DTE real (JSON o PDF): se añade a la búsqueda para que un
        // número corto que también aparece en Excel de cobro/plantillas NO gane sobre el
        // correo del DTE. Si ninguna variante lo cumple, se reintenta sin este filtro.
        'dte_adjunto_query' => env('PPQ_GMAIL_DTE_ADJUNTO_QUERY', '(filename:json OR filename:pdf)'),
        // Label donde Calleja deja los albaranes.
        'label_albaranes' => env('PPQ_GMAIL_LABEL', 'Calleja_Albaranes'),
        // Carpeta donde se guardan los PDF/JSON descargados de los correos.
        'storage_dir' => env('PPQ_GMAIL_STORAGE_DIR', 'ppq/gmail'),
    ],
];
