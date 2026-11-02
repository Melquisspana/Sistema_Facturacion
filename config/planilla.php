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
    | Es CONTROL DE REMUNERACIONES CON IMPORTES REVISADOS. Para quien va con descuentos
    | de ley, propone ISSS, AFP y renta en la quincena regular, con los parámetros de
    | `config/planilla_descuentos.php`, y una persona los revisa o los corrige.
    |
    | NO calcula aporte patronal, vacaciones, aguinaldo, indemnización ni horas extra, y
    | no infiere nada a partir de las marcaciones de Asistencia.
    |
    */

    'calculo_legal' => true,

    // Cuántos pagos hay en un mes. Melqui confirmó la quincena: el importe por pago es el
    // sueldo mensual ÷ 2, y los topes mensuales de ley se reparten igual.
    'pagos_por_mes' => 2,

    // Sector de la empresa en la tabla de salarios mínimos de
    // `config/planilla_descuentos.php`.
    'sector_salario_minimo' => env('PLANILLA_SECTOR_SALARIO_MINIMO', 'industria'),

    /*
    |--------------------------------------------------------------------------
    | Contrato de trabajo
    |--------------------------------------------------------------------------
    |
    | Los datos personales del patrono van por `.env` y sin valores por defecto: el
    | repositorio es público, y sin ellos el contrato no se genera en vez de salir con un
    | patrono inventado. Razón social y NIT salen de `empresas`.
    |
    */

    'contrato' => [
        'patrono' => [
            'sexo' => env('PLANILLA_CONTRATO_PATRONO_SEXO', ''),
            'fecha_nacimiento' => env('PLANILLA_CONTRATO_PATRONO_NACIMIENTO', ''),
            'estado_civil' => env('PLANILLA_CONTRATO_PATRONO_ESTADO_CIVIL', ''),
            'profesion' => env('PLANILLA_CONTRATO_PATRONO_PROFESION', ''),
            'domicilio' => env('PLANILLA_CONTRATO_PATRONO_DOMICILIO', ''),
            'residencia' => env('PLANILLA_CONTRATO_PATRONO_RESIDENCIA', ''),
            'nacionalidad' => env('PLANILLA_CONTRATO_PATRONO_NACIONALIDAD', ''),
            'dui' => env('PLANILLA_CONTRATO_PATRONO_DUI', ''),
            'dui_expedido_en' => env('PLANILLA_CONTRATO_PATRONO_DUI_EXPEDIDO_EN', ''),
            'dui_expedido_el' => env('PLANILLA_CONTRATO_PATRONO_DUI_EXPEDIDO_EL', ''),
            'actividad' => env('PLANILLA_CONTRATO_ACTIVIDAD', ''),
            'ciudad' => env('PLANILLA_CONTRATO_CIUDAD', ''),
            'direccion_pago' => env('PLANILLA_CONTRATO_DIRECCION_PAGO', ''),
        ],
    ],

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
