<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Módulo Rutas / Cobros — seguimiento de documentos
    |--------------------------------------------------------------------------
    | Parámetros del seguimiento documental de una salida. NADA de esto toca la
    | emisión de DTE, correlativos, firma, transmisión, invalidaciones ni Planta:
    | el módulo solo CONSULTA documentos ya emitidos y anota sobre ellos hechos
    | operativos (a qué salida fueron, si volvió el papel, si requieren NC).
    */

    /*
    | Ventana de fechas para PROPONER documentos candidatos a una salida.
    |
    | Un CCF de una sala de la ruta emitido tres meses antes casi nunca pertenece
    | a la salida que se está armando hoy, y ofrecerlo entierra los que sí. La
    | ventana se calcula alrededor del período de la salida (inicio − antes,
    | fin + después), no alrededor de "hoy": armar una salida de la semana pasada
    | tiene que seguir proponiendo los documentos de esa semana.
    */
    'candidatos_dias_antes' => (int) env('RUTAS_CANDIDATOS_DIAS_ANTES', 15),
    'candidatos_dias_despues' => (int) env('RUTAS_CANDIDATOS_DIAS_DESPUES', 15),

    /*
    | Serie (punto de venta) habilitada para la ASIGNACIÓN AUTOMÁTICA.
    |
    | Solo P002 —la serie viva del sistema nuevo— se asocia sola. P001 es la serie
    | histórica de Conta Portable: sus documentos se agregan a mano y con el
    | usuario mirando, porque el sistema no los emitió y no puede dar por sentado
    | ni su sala ni su vigencia.
    |
    | Null desactiva por completo la asignación automática (queda todo manual).
    */
    'punto_venta_automatico' => env('RUTAS_PUNTO_VENTA_AUTOMATICO', 'P002'),

    /*
    | Cuántos días hacia atrás mira el comando `rutas:asociar-documentos` cuando
    | no se le pasa `--dias`. Es solo el alcance del barrido; las reglas de
    | seguridad (una única salida en curso, documento sin dueño) no dependen de él.
    */
    'asociacion_dias' => (int) env('RUTAS_ASOCIACION_DIAS', 7),

    /*
    | Ventana por defecto de la BANDEJA de documentos (todas las salidas juntas).
    |
    | No es solo una comodidad de pantalla: la bandeja resuelve en PHP los filtros
    | derivados (entrega, estado de cobro) porque no son columnas, y eso obliga a
    | hidratar las filas que entran. La ventana es el tope que mantiene ese trabajo
    | acotado. El usuario puede moverla con los filtros de fecha, pero no quitarla.
    */
    'bandeja_dias' => (int) env('RUTAS_BANDEJA_DIAS', 60),

    /*
    |--------------------------------------------------------------------------
    | Umbrales de la bandeja de EXCEPCIONES
    |--------------------------------------------------------------------------
    | Desde cuántos días una espera normal pasa a ser algo que alguien tiene que
    | mirar. Empiezan conservadores a propósito: una bandeja que avisa de todo se
    | deja de mirar, y entonces deja de servir para lo único que existe —que el
    | hueco se vea el día que aparece—.
    |
    | Ninguno dispara correos ni notificaciones todavía: solo deciden qué se pinta
    | en la pantalla de excepciones.
    */
    'excepciones' => [
        // Entregado según el albarán y el CCF físico firmado sigue sin volver.
        'dias_sin_papel' => (int) env('RUTAS_EXCEPCION_DIAS_SIN_PAPEL', 5),
        // Salió en ruta y todavía no hay albarán que pruebe la entrega.
        'dias_sin_albaran' => (int) env('RUTAS_EXCEPCION_DIAS_SIN_ALBARAN', 3),
    ],
];
