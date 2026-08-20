<?php

/*
|--------------------------------------------------------------------------
| Datos del emisor — RESPALDO DE PRESENTACIÓN ÚNICAMENTE
|--------------------------------------------------------------------------
|
| NO es la fuente de verdad del emisor. La fuente FISCAL es la tabla `empresas`
| (editable en Configuración → Empresa emisora): de ahí salen razón social, NIT,
| NRC, actividad y dirección del bloque `emisor` del JSON del MH, vía
| App\Services\Dte\MapeadorDteSalida. Este archivo NO participa en el JSON, ni en
| la firma, ni en la transmisión, ni en los correlativos.
|
| Su único uso real son las representaciones GRÁFICAS del documento:
|   - resources/views/facturacion/pdf.blade.php
|   - resources/views/facturacion/imprimir.blade.php
| Ambas resuelven cada dato como «empresa real → este respaldo → '—'», para que un
| PDF no salga en blanco si la empresa enlazada al documento todavía no tiene ese
| campo cargado. Es estética, nunca un dato fiscal.
|
| No contiene credenciales y no debe contenerlas nunca.
|
*/

return [
    'nit' => env('COMPANY_NIT', ''),
    'nrc' => env('COMPANY_NRC', ''),
    'nombre' => env('COMPANY_NAME', 'Dulces La Negrita'),
    'nombre_comercial' => env('COMPANY_TRADE_NAME', 'Dulces La Negrita'),

    'actividad_economica' => [
        'codigo' => env('COMPANY_ACTIVITY_CODE', ''),
        'descripcion' => env('COMPANY_ACTIVITY_DESC', ''),
    ],

    'contacto' => [
        'telefono' => env('COMPANY_PHONE', ''),
        'correo' => env('COMPANY_EMAIL', ''),
    ],

    'direccion' => [
        'departamento' => env('COMPANY_DEPARTMENT', ''),
        'municipio' => env('COMPANY_MUNICIPALITY', ''),
        'complemento' => env('COMPANY_ADDRESS', ''),
    ],
];
