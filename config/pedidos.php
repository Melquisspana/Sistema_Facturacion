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

];
