<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desde cuándo se controlan las entregas
    |--------------------------------------------------------------------------
    |
    | Solo los CCF emitidos desde esta fecha cuentan como «pendientes de entregar».
    | Los anteriores se entregaron antes de que existiera este control y no tienen
    | registro: sin este corte aparecerían todos como pendientes.
    |
    | Formato AAAA-MM-DD.
    |
    */

    'entregas_desde' => env('RUTAS_ENTREGAS_DESDE', '2026-09-28'),

    /*
    |--------------------------------------------------------------------------
    | Ambiente de los CCF que se entregan
    |--------------------------------------------------------------------------
    |
    | Vacío = el ambiente operativo de la instalación (DTE_AMBIENTE): producción en
    | el servidor, pruebas en desarrollo. Solo se fija para probar Rutas en
    | desarrollo sobre una copia de la base de producción ('01') sin cambiar el
    | ambiente fiscal de la instalación.
    |
    */

    'ambiente_ccf' => env('RUTAS_AMBIENTE_CCF'),

];
