<?php

/*
| Exportaciones / Lista de Empaque — módulo administrativo paralelo.
| NO interviene en la emisión de DTE, correlativos, firma ni transmisión.
*/
return [
    // Plantilla Excel oficial (relativa a storage/app). SOLO se usa su hoja "Lista".
    'plantilla' => env('EXPORTACIONES_PLANTILLA', 'templates/exportaciones/lista_empaque.xlsx'),

    // Valores por defecto del encabezado al crear una exportación (editables en el formulario).
    'exportador_nombre' => env('EXPORTACIONES_EXPORTADOR', 'Titular de Ejemplo'),
    'exportador_direccion' => env('EXPORTACIONES_EXPORTADOR_DIR', 'Calle de Ejemplo 123, Localidad A'),
    'fda_reg_number' => env('EXPORTACIONES_FDA', '00000000001'),
];
