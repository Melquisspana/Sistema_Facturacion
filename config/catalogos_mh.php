<?php

/*
|--------------------------------------------------------------------------
| Catálogos oficiales del MH (CAT-001..CAT-033)
|--------------------------------------------------------------------------
|
| REGISTRO VERSIONADO del Excel oficial. Antes el importador tomaba «el primer
| .xlsx que hubiera» en resources/dte/catalogos (glob), así que agregar una
| revisión nueva cambiaba la fuente activa por orden alfabético y sin dejar
| rastro de QUÉ archivo se importó. Acá la versión activa es una decisión
| explícita y verificable por hash.
|
| Reglas:
|   - Las revisiones NO se borran ni se sobrescriben: se agregan. El archivo
|     anterior queda en el repo y en este registro para poder reproducir el
|     estado de un DTE emitido bajo esa vigencia.
|   - `sha256` es obligatorio: {@see App\Support\Dte\CatalogoOficialMh} lo
|     verifica en cada importación y aborta si no coincide. Es lo que impide
|     importar un archivo alterado a mano o descargado a medias.
|
*/

return [

    /*
    | Versión VIGENTE. Es la única que se importa a `catalogos_mh`.
    */
    'activo' => '2026-07-01',

    'versiones' => [

        /*
        | Revisión de mayo 2026. Se conserva por trazabilidad: los DTE emitidos
        | antes del 1 de julio de 2026 se serializaron contra este catálogo, en
        | el que CAT-013 tenía 10 = CABAÑAS OESTE y 11 = CABAÑAS ESTE.
        */
        '2026-05' => [
            'archivo' => 'Catálogos - Facturación Electrónica 2026-05.xlsx',
            'sha256' => '959b50b43e3a98f7be54c597d043804d0b8360fd3f9f120839f454abe7e1481f',
            'descripcion' => 'Revisión de mayo 2026 (anterior a la corrección de CAT-013 Cabañas).',
        ],

        /*
        | Revisión OFICIAL vigente desde el 1 de julio de 2026. Diferencias
        | reales contra la de mayo (verificadas celda por celda sobre las 33
        | secciones, no solo sobre las que se esperaba que cambiaran):
        |
        |   CAT-013  10 = CABAÑAS ESTE   (antes CABAÑAS OESTE)
        |            11 = CABAÑAS OESTE  (antes CABAÑAS ESTE)
        |   CAT-027  + 43 = Z.F. INHDELVA (código nuevo)
        |
        | Además CAT-002 y CAT-008 pasaron a traer el código con cero a la
        | izquierda ("03" en vez de "3"). Es un cambio de FORMATO, no de
        | contenido: CAT-008 conserva 03 = ILOBASCO y 06 = SENSUNTEPEQUE. No
        | afecta a nadie porque el único consumidor de CAT-008
        | ({@see App\Console\Commands\VincularCodigoDistritoMh}) ya normalizaba
        | a dos dígitos, y CAT-002 no se consulta por código.
        */
        '2026-07-01' => [
            'archivo' => 'Catálogos - Facturación Electrónica 2026-07-01.xlsx',
            'sha256' => 'e86d7edc503d876564cd2bf9b251fb100f838199330c0c513048f4075669b2c6',
            'descripcion' => 'Revisión oficial vigente desde el 1 de julio de 2026.',
        ],

    ],

];
