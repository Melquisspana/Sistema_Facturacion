<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor del módulo
    |--------------------------------------------------------------------------
    |
    | Con false, TODA ruta de /planilla responde 404 —para todos los roles, incluido
    | administrador— y el área no se dibuja en el selector. Es el candado mientras la
    | fase 3 se construye, igual que lo fue para Gastos.
    |
    | Este corte NO habilita pagos automáticos, correo ni procesos periódicos: nada de
    | eso existe todavía en planilla.
    |
    */

    'enabled' => env('PLANILLA_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Lo que este módulo NO hace, y conviene tener escrito
    |--------------------------------------------------------------------------
    |
    | Esta entrega es CONTROL DE REMUNERACIONES CON IMPORTES REVISADOS, no nómina
    | legal. No calcula ISSS, AFP, renta, vacaciones, aguinaldo, indemnización ni
    | horas extra, y no infiere nada a partir de las marcaciones de Asistencia.
    |
    | Cada importe lo escribe y lo revisa una persona. Si algún día se agrega cálculo
    | legal, será otra decisión, con su propia validación y su propia fase.
    |
    */

    'calculo_legal' => false,

    /*
    |--------------------------------------------------------------------------
    | Conceptos sugeridos
    |--------------------------------------------------------------------------
    |
    | Solo para autocompletar el campo de texto y que no haya veinte formas de
    | escribir «viático». NO son un catálogo cerrado ni llevan importe asociado:
    | quien prepara la planilla puede escribir cualquier otro.
    |
    */

    'ingresos_sugeridos' => ['Bono', 'Comisión', 'Viáticos', 'Horas adicionales', 'Ajuste a favor'],

    'descuentos_sugeridos' => ['Anticipo', 'Préstamo', 'Ausencia', 'Ajuste en contra'],

];
