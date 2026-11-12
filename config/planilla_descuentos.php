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
            // Tramo II: la deducción de US$1,600 (Art. 1 lit. e) no se aplica en la quincena,
            // solo en el recálculo de diciembre (lo confirmó la contadora el 04/11/2026).
            'renta' => [
                'concepto' => 'Renta',
                'tercero' => 'Ministerio de Hacienda',
                'tramos' => [
                    // Hasta 275.00: sin retención.
                    ['desde' => '0.00', 'porcentaje' => '0.00', 'exceso' => '0.00', 'cuota' => '0.00'],
                    // 275.01 a 447.62.
                    ['desde' => '275.01', 'porcentaje' => '10.00', 'exceso' => '275.00', 'cuota' => '8.83'],
                    // 447.63 a 1,019.05.
                    ['desde' => '447.63', 'porcentaje' => '20.00', 'exceso' => '447.62', 'cuota' => '30.00'],
                    // 1,019.06 en adelante.
                    ['desde' => '1019.06', 'porcentaje' => '30.00', 'exceso' => '1019.05', 'cuota' => '144.28'],
                ],
                // Tabla MENSUAL, para la planilla mensual. Fuente: Decreto Ejecutivo No. 10 del
                // 30/04/2025, Art. 1 lit. a; la quincenal de arriba es la mitad de esta. Por
                // confirmar con la contadora: se transcribió sin el texto oficial a la vista.
                'tramos_mensuales' => [
                    // Hasta 550.00: sin retención.
                    ['desde' => '0.00', 'porcentaje' => '0.00', 'exceso' => '0.00', 'cuota' => '0.00'],
                    // 550.01 a 895.24.
                    ['desde' => '550.01', 'porcentaje' => '10.00', 'exceso' => '550.00', 'cuota' => '17.67'],
                    // 895.25 a 2,038.10.
                    ['desde' => '895.25', 'porcentaje' => '20.00', 'exceso' => '895.24', 'cuota' => '60.00'],
                    // 2,038.11 en adelante.
                    ['desde' => '2038.11', 'porcentaje' => '30.00', 'exceso' => '2038.10', 'cuota' => '288.57'],
                ],
            ],

            // Recálculo de renta en el último pago de junio y de diciembre, por número de
            // mes. Fuente: Decreto 10, Art. 1 lit. f. Se aplica la tabla a lo gravado
            // acumulado en el año hasta ese pago (enero a junio, o el año entero), se resta
            // la renta ya retenida y la diferencia es lo que se retiene. «Si la diferencia
            // es negativa no se retendrá valor alguno»: queda en cero, sin devolución.
            //
            // El mismo literal pide considerar en el recálculo, en el Tramo II de las dos
            // tablas, la deducción fija de US$1,600 (LISR Art. 29 num. 7) para quien gana
            // US$9,100 o menos al año. Según la contadora (04/11/2026), se resta solo en
            // diciembre, y el tope se compara con lo gravado (sueldo menos ISSS y AFP).
            // El aguinaldo no lleva renta y las planillas fuera de calendario suman solo si
            // se les aplicaron los descuentos de ley: el sistema no se los calcula, así que
            // lo acumulado cuenta solo las quincenas y las mensuales normales (AcumuladoRenta).
            'recalculo' => [
                6 => [
                    'tramos' => [
                        // Hasta 3,300.00: sin retención.
                        ['desde' => '0.00', 'porcentaje' => '0.00', 'exceso' => '0.00', 'cuota' => '0.00'],
                        // 3,300.01 a 5,371.44.
                        ['desde' => '3300.01', 'porcentaje' => '10.00', 'exceso' => '3300.00', 'cuota' => '106.20'],
                        // 5,371.45 a 12,228.60.
                        ['desde' => '5371.45', 'porcentaje' => '20.00', 'exceso' => '5371.44', 'cuota' => '360.00'],
                        // 12,228.61 en adelante.
                        ['desde' => '12228.61', 'porcentaje' => '30.00', 'exceso' => '12228.60', 'cuota' => '1731.42'],
                    ],
                ],
                12 => [
                    'tramos' => [
                        // Hasta 6,600.00: sin retención.
                        ['desde' => '0.00', 'porcentaje' => '0.00', 'exceso' => '0.00', 'cuota' => '0.00'],
                        // 6,600.01 a 10,742.86.
                        ['desde' => '6600.01', 'porcentaje' => '10.00', 'exceso' => '6600.00', 'cuota' => '212.12'],
                        // 10,742.87 a 24,457.14.
                        ['desde' => '10742.87', 'porcentaje' => '20.00', 'exceso' => '10742.86', 'cuota' => '720.00'],
                        // 24,457.15 en adelante.
                        ['desde' => '24457.15', 'porcentaje' => '30.00', 'exceso' => '24457.14', 'cuota' => '3462.86'],
                    ],
                    // Se resta de lo gravado del año cuando no pasa de `hasta`. Con ese
                    // límite la persona nunca está más allá del Tramo II. El límite se mide
                    // sobre lo gravado, no sobre el sueldo bruto (contadora, 04/11/2026).
                    'deduccion' => ['importe' => '1600.00', 'hasta' => '9100.00',
                        'aviso' => 'Se restó la deducción de $1,600 porque lo gravado del año no pasa de $9,100 (Decreto 10, art. 1 lit. f).'],
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
