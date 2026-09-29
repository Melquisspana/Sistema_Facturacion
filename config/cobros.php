<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cobros Calleja — seguimiento de factura a pago
    |--------------------------------------------------------------------------
    | Este módulo NO emite, NO firma, NO transmite y NO recalcula ningún valor
    | fiscal: lee documentos ya aceptados por Hacienda y sigue su cobro.
    |
    | Reutiliza lo que ya decide PPQ y no duplica sus llaves: la tolerancia de
    | importes sale de `ppq.diferencia_coincide` y el código de proveedor del
    | perfil documental del cliente.
    */

    /*
    | Antigüedad (en días, al momento del alta) a partir de la cual un documento
    | SIN antecedentes se marca para REVISIÓN HISTÓRICA en vez de declararse «sin
    | presentar y sin pagar».
    |
    | El módulo nace con años de facturas ya emitidas detrás. De las viejas no
    | sabemos si se presentaron ni si se cobraron: el circuito anterior no dejaba
    | ese rastro documento por documento. Declararlas pendientes las convertiría
    | en una deuda inventada, y declararlas cobradas escondería lo que sí falta.
    | Se marcan, se ven y una persona decide.
    |
    | Las recién emitidas entran limpias: de esas sí sabemos que nadie las
    | presentó todavía, porque el seguimiento existió desde su primer día.
    */
    'dias_revision_historica' => (int) env('COBROS_DIAS_REVISION_HISTORICA', 30),

    // Fecha (AAAA-MM-DD) desde la que el seguimiento lleva la historia. Lo emitido antes y
    // sin antecedente queda en revisión histórica; lo posterior nunca. Vacía = regla de
    // los días de arriba.
    'inicio_seguimiento' => env('COBROS_INICIO_SEGUIMIENTO'),

    /*
    |--------------------------------------------------------------------------
    | Alta automática de los documentos aceptados
    |--------------------------------------------------------------------------
    | INTERRUPTOR, apagado por defecto. Gobierna DOS puertas: la tarea programada
    | y el propio comando cuando recibe `--aplicar`, así que una invocación
    | accidental por fuera del planificador tampoco escribe nada.
    |
    | Por qué la automática hace falta: el módulo existe para controlar «cada
    | factura, incluidas las que nunca se presentaron». Mientras el alta dependa de
    | que alguien pulse un botón, la factura olvidada es justo la que no entra, y el
    | agujero no se ve por ningún lado. El botón de la pantalla sigue existiendo
    | como RECUPERACIÓN —planificador caído, resultado necesario ahora mismo—, no
    | como el camino principal.
    |
    | El dry-run (sin `--aplicar`) está siempre disponible: es el paso previo con el
    | que se comprueba qué entraría antes de encender esto.
    |
    | El alta solo LEE `dtes` ya aceptados y escribe en `cobro_documentos`: no emite,
    | no firma, no transmite y no bloquea nada que la emisión necesite.
    */
    'alta' => [
        'automatica' => (bool) env('COBROS_ALTA_AUTO', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vinculación automática de albaranes
    |--------------------------------------------------------------------------
    | INTERRUPTOR APARTE, apagado por defecto. `alta.automatica` decide si el
    | CCF/NC entra al seguimiento; esta decide si, además, la MISMA corrida
    | intenta UNIR el albarán de entrega cuando `VinculadorAlbaranes` lo audita
    | como ÚNICO y sin contradicciones (ver esa clase para qué identifica y qué
    | solo contradice: nunca importe, fecha ni correlativo suelto).
    |
    | Por qué es una llave propia y no basta con la opción `--vincular` del
    | comando: esa opción dice QUÉ hace una corrida puntual; esta dice si la
    | corrida PROGRAMADA —sin nadie mirando— puede escribir un vínculo por su
    | cuenta. Encenderla sin haber auditado antes convertiría un candidato
    | dudoso en un vínculo silencioso.
    |
    | Antes de encenderla: correr `cobros:sincronizar --cliente=ID --vincular`
    | SIN `--aplicar` sobre datos reales, revisar cuántos saldrían «revisar» y
    | por qué, y solo entonces ponerla en true en el .env del servidor.
    |
    | El botón «Revisar vinculación» de la pantalla NO depende de esta llave:
    | sigue siendo la recuperación manual, igual que con el alta.
    */
    'vinculacion' => [
        'automatica' => (bool) env('COBROS_VINCULACION_AUTO', false),
    ],

    /*
    | Dónde queda la copia del archivo de solicitud generado. Es la prueba de QUÉ
    | se presentó, direccionada por el SHA-256 de su contenido: regenerar el mismo
    | contenido escribe el mismo lugar y no duplica nada.
    */
    'solicitudes' => [
        'storage_dir' => env('COBROS_SOLICITUDES_STORAGE_DIR', 'cobros/solicitudes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lectura del correo de respuesta del cliente
    |--------------------------------------------------------------------------
    | SOLO LECTURA. El sistema no envía, no responde y no modifica mensajes.
    | Se apoya en la conexión de Gmail que ya usa PPQ (`ppq.gmail.*`): no hay una
    | segunda credencial ni un segundo buzón.
    */
    'correo' => [
        // Interruptor propio: que PPQ pueda hablar con Gmail no significa que este
        // módulo deba leer el buzón por su cuenta. Mismo criterio que
        // `ppq.albaranes.sincronizacion_automatica`.
        'enabled' => (bool) env('COBROS_CORREO_ENABLED', false),

        /*
        | Consulta de Gmail para los acuses del cliente.
        |
        | NO filtra por asunto, y eso es lo importante. El acuse real llega como respuesta a
        | nuestro propio correo, así que su asunto es «RE: SOLICITUD DE QUEDAN (PRONTO
        | PAGO)» y el «RECIBIDO (000123202609040951)» viene DENTRO del cuerpo. Un
        | `subject:(RECIBIDO OR OBSERVACIONES)` no lo encontraba nunca: buscaba la marca
        | donde no está.
        |
        | Gmail busca en el cuerpo por defecto, así que sin `subject:` la consulta cubre las
        | dos formas —la marca en el asunto y la marca en el cuerpo—. Se afina en el .env
        | sin tocar código.
        */
        'query' => env('COBROS_CORREO_QUERY', '("RECIBIDO" OR "OBSERVACIONES" OR "SOLICITUD DE QUEDAN")'),

        // Cuántos mensajes trae como mucho una corrida. La búsqueda PAGINA hasta juntarlos,
        // así que subir este número lee más atrás en el buzón en vez de repetir los mismos.
        'limite' => (int) env('COBROS_CORREO_LIMITE', 50),

        /*
        | Y esta tercera llave decide si además lo hace SOLO, cada media hora.
        |
        | Son tres y cada una responde una pregunta distinta: `ppq.gmail.enabled` si el
        | sistema PUEDE hablar con Gmail; `cobros.correo.enabled` si este módulo puede leer
        | el buzón cuando alguien se lo pide; y esta, si lo hace sin que nadie se lo pida.
        | Juntarlas obligaría a encender la automática para poder probar la lectura, que es
        | exactamente al revés de como hay que hacerlo.
        */
        'automatica' => (bool) env('COBROS_CORREO_AUTO', false),
    ],
];
