<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor del módulo
    |--------------------------------------------------------------------------
    |
    | Con false, TODA ruta de /gastos responde 404 —para todos los roles, incluido
    | administrador— y el módulo no se dibuja en el menú. Es el candado mientras la
    | fase 1 se construye. Ver App\Http\Middleware\ModuloGastosActivo.
    |
    | Este corte NO habilita importaciones desde Compras, correo, avisos ni
    | procesos periódicos: nada de eso existe todavía.
    |
    */

    'enabled' => env('GASTOS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Monedas admitidas
    |--------------------------------------------------------------------------
    |
    | Solo monedas de DOS decimales, que es lo que asume App\Services\Gastos\Dinero
    | (centavos enteros). Agregar una de otra precisión exige revisar ese servicio
    | antes, no solo esta lista. No hay conversión entre monedas: cada una lleva sus
    | propios totales y un pago no puede mezclar dos.
    |
    */

    'monedas' => ['USD'],

    /*
    |--------------------------------------------------------------------------
    | Métodos de pago
    |--------------------------------------------------------------------------
    |
    | Cómo salió el dinero, nada más. NO es un catálogo de cuentas ni de bancos: el
    | módulo no administra cuentas, no calcula saldos y no concilia. Registrar un
    | pago no exige elegir de dónde salió.
    |
    */

    'metodos' => [
        'transferencia' => 'Transferencia',
        'efectivo' => 'Efectivo',
        'tarjeta' => 'Tarjeta',
        'otro' => 'Otro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Archivos adjuntos
    |--------------------------------------------------------------------------
    |
    | Documentos del gasto y comprobantes del pago. Se validan por CONTENIDO (no por
    | la extensión que mande el cliente), viven en disco privado con nombre generado
    | por el servidor y solo se sirven por controlador autorizado.
    |
    | Sin SVG ni HTML: son ejecutables en un navegador.
    |
    */

    'max_archivo_kb' => 10240,
    'max_archivos' => 10,
    'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    /*
    |--------------------------------------------------------------------------
    | Cuotas
    |--------------------------------------------------------------------------
    |
    | Tope de vencimientos por gasto. Un gasto sin calendario tiene UNA cuota (con
    | fecha o sin ella); no se duplica el gasto por cada cuota.
    |
    */

    'max_cuotas' => 36,

    /*
    |--------------------------------------------------------------------------
    | Recurrencias
    |--------------------------------------------------------------------------
    |
    | `generacion_automatica` es el interruptor de la tarea programada. Apagado por
    | defecto, y por la misma razón que en Compras y PPQ: instalar el planificador en
    | un servidor no puede encender solo un proceso que CREA DEUDA. Con él apagado las
    | reglas se pueden configurar y generar a mano desde la pantalla; lo único que no
    | ocurre es la generación desatendida.
    |
    | `ventana_recuperacion_dias` acota cuánto hacia atrás recupera una corrida después
    | de que el servicio estuvo caído. Es un tope de SEGURIDAD, no una preferencia: una
    | regla mensual vigente desde hace tres años, generada de golpe, inventaría 36
    | deudas que nadie pidió. Lo que queda fuera se informa para que una persona
    | decida, nunca se crea solo.
    |
    */

    'recurrencias' => [
        'generacion_automatica' => env('GASTOS_RECURRENCIAS_AUTO', false),
        'ventana_recuperacion_dias' => 62,
    ],

    /*
    |--------------------------------------------------------------------------
    | Avisos y resúmenes
    |--------------------------------------------------------------------------
    |
    | Dos interruptores separados, y hacen falta los dos para que salga un correo:
    | `avisos.automaticos` para el proceso programado, y la preferencia de cada
    | persona. Ninguno de los dos alcanza para saltarse el candado de correo real:
    | fuera de `production` el resumen se registra como SIMULADO y no se envía.
    | Ver App\Support\Correo\CandadoCorreoReal.
    |
    | Los días de anticipación son la propuesta inicial (7, 3 y 0 días) y cada
    | persona los edita. No son una regla del sistema.
    |
    */

    'avisos' => [
        'automaticos' => env('GASTOS_AVISOS_AUTO', false),
        'dias_anticipacion' => [7, 3, 0],
        // Tope de filas por resumen. Un correo con cuatrocientas líneas no se lee;
        // pasado el tope, el correo remite a la pantalla.
        'max_filas_resumen' => 50,
    ],

];
