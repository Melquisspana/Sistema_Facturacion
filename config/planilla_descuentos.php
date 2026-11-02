<?php

/*
|--------------------------------------------------------------------------
| Parámetros de ley de la planilla
|--------------------------------------------------------------------------
|
| Tasas, topes, tabla de renta y salario mínimo, cada uno con su fecha de
| vigencia. Cuando la ley cambia se AGREGA una versión: la anterior se queda,
| porque una quincena vieja se sigue explicando con lo que regía entonces.
|
| Rige la versión más reciente cuyo `vigente_desde` no sea posterior a la fecha
| consultada. Si no hay ninguna, el sistema lo dice: nunca calcula con cero.
|
| Los importes y porcentajes van como TEXTO a propósito. Se convierten a enteros
| sin pasar por un número decimal, que no puede representar 7.25 exacto.
|
| Lo marcado «por confirmar» está en la lista para la contadora: ver el comentario
| de cada valor.
|
*/

return [

    'versiones' => [
        [
            // El Ministerio de Hacienda dice «a partir del mes de mayo de 2025». El
            // Decreto rige ocho días después de publicarse; no se pudo confirmar el
            // número de Diario Oficial. No afecta: la planilla arrancó después.
            'vigente_desde' => '2025-05-01',

            // ISSS, trabajador 3 % y máximo cotizable de US$1,000 al mes. Fuente: ISSS,
            // «Modificación del salario máximo de cotización para empleadores», vigente
            // desde el 01/08/2015. Tope confirmado por la contadora, 02/11/2026.
            'isss' => ['concepto' => 'ISSS', 'tercero' => 'ISSS', 'tasa' => '3.00', 'tope_mensual' => '1000.00'],

            // AFP, trabajador 7.25 %. Fuente: Ley Integral del Sistema de Pensiones
            // (Decreto 614), tasa de cotización. Sin tope: el Art. 14 no fija máximo. Tasa y
            // ausencia de tope confirmadas por la contadora, 02/11/2026. Si la ley fijara un
            // tope, se escribe acá y el cálculo lo respeta.
            'afp' => ['concepto' => 'AFP', 'tercero' => 'AFP', 'tasa' => '7.25', 'tope_mensual' => null],

            // Renta, tabla QUINCENAL. Fuente: Decreto Ejecutivo No. 10 del 30/04/2025, Art. 1
            // lit. b. La base es el total del período menos ISSS y AFP (Art. 1 lit. d).
            //
            // Tramo II: el Art. 1 lit. e pide considerar la deducción de US$1,600 para quien
            // gana US$9,100 o menos al año, y el decreto no da la fórmula quincenal. No se
            // inventa: se avisa para revisarla con la contadora.
            'renta' => [
                'concepto' => 'Renta',
                'tercero' => 'Ministerio de Hacienda',
                'tramos' => [
                    // Hasta 275.00: sin retención.
                    ['desde' => '0.00', 'porcentaje' => '0.00', 'exceso' => '0.00', 'cuota' => '0.00'],
                    // 275.01 a 447.62.
                    ['desde' => '275.01', 'porcentaje' => '10.00', 'exceso' => '275.00', 'cuota' => '8.83',
                        'aviso' => 'Revisá con la contadora la deducción de $1,600 (Decreto 10, art. 1 lit. e).'],
                    // 447.63 a 1,019.05.
                    ['desde' => '447.63', 'porcentaje' => '20.00', 'exceso' => '447.62', 'cuota' => '30.00'],
                    // 1,019.06 en adelante.
                    ['desde' => '1019.06', 'porcentaje' => '30.00', 'exceso' => '1019.05', 'cuota' => '144.28'],
                ],
            ],
        ],
    ],

    // Salario mínimo MENSUAL por sector. Fuente: Decreto Ejecutivo No. 12 del
    // 24/05/2025, que reforma el Decreto 11, Art. 2, Diario Oficial No. 96, Tomo 447, del
    // 26/05/2025; rige desde el 01/06/2025. Melqui confirmó el sector industria.
    'salario_minimo' => [
        [
            'vigente_desde' => '2025-06-01',
            'sectores' => [
                'comercio_servicios' => '408.80',
                'maquila_textil' => '402.32',
                'industria' => '408.80',
                'ingenios_azucareros' => '408.80',
                'agroindustria' => '408.80',
                'beneficios_cafe' => '305.23',
                'agropecuario' => '272.53',
                'recoleccion_cana' => '305.23',
                'recoleccion_cafe' => '272.53',
            ],
        ],
    ],

    // Ingresos que no cotizan a ISSS ni AFP, por el nombre con que se escriben en la
    // quincena. Fuente: Ley Integral del Sistema de Pensiones, Art. 14; Reglamento del
    // ISSS, Art. 3, citado en el documento del ISSS de arriba. Lo demás, como comisiones
    // y horas adicionales, sí entra en la base.
    'ingresos_fuera_de_base' => ['Viáticos', 'Bono', 'Aguinaldo'],

    // Pagos que llevan otro procedimiento de renta (Decreto 10, Art. 1 lit. g). No se
    // calculan: se avisa.
    'ingresos_extraordinarios' => ['Bono', 'Aguinaldo'],

];
