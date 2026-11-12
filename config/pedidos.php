<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desde qué día aparecen los pedidos en la fábrica
    |--------------------------------------------------------------------------
    |
    | Los pedidos de sala con fecha anterior no aparecen en «Por mandar» de
    | /fabrica/pedidos. Sirve para estrenar la pantalla sin que aparezcan de golpe
    | los pedidos sacados antes. Vacío = todos.
    |
    | Formato AAAA-MM-DD. Se cambia desde Configuración › Rutas.
    |
    */

    'fabrica_desde' => env('PEDIDOS_FABRICA_DESDE'),

    /*
    |--------------------------------------------------------------------------
    | A quién va el resumen diario de las 18:00
    |--------------------------------------------------------------------------
    |
    | Uno o varios correos separados por coma. Lo carga el administrador en
    | Configuración › Rutas: nunca se escribe en el repositorio. Vacío = no se
    | manda (`pedidos:correo-diario` lo deja en el log).
    |
    */

    'correo_resumen_diario' => env('PEDIDOS_CORREO_RESUMEN_DIARIO'),

];
