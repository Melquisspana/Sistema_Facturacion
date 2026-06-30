<?php

/*
|--------------------------------------------------------------------------
| Datos del emisor (Dulces La Negrita)
|--------------------------------------------------------------------------
|
| Valores por defecto del emisor, leídos del .env. Los datos OFICIALES y
| editables vivirán en la tabla `empresas` (se crea en una fase posterior);
| este archivo sirve como respaldo/configuración base y para sembrar el
| registro inicial. No contiene credenciales.
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
