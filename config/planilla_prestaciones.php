<?php

/*
|--------------------------------------------------------------------------
| Aguinaldo, Quincena 25, vacaciones e indemnización
|--------------------------------------------------------------------------
|
| Parámetros con vigencia y fuente. Cuando la ley cambia se agrega una versión: las
| anteriores se quedan. Si no hay versión para el año o la fecha, el sistema lo dice y
| no calcula.
|
| Lo marcado «por confirmar» está en la lista para la contadora.
|
*/

return [

    // Una versión por AÑO, no con «desde»: la exención de renta es transitoria y se
    // aprueba cada año. Que falte el año siguiente obliga a revisarla.
    'aguinaldo' => [
        2026 => [
            // Fecha de corte para saber cuánto tiempo lleva la persona. Código de Trabajo
            // Art. 197, reformado; Asamblea Legislativa, 23/09/2026.
            'corte' => '12-12',

            // Del 1 de octubre al 20 de diciembre. Código de Trabajo Art. 200, reformado;
            // Asamblea Legislativa, 23/09/2026. Por confirmar: número del decreto.
            'pago_desde' => '10-01',
            'pago_hasta' => '12-20',

            // Días de salario según los años de servicio. Código de Trabajo Art. 198. Son
            // el MÍNIMO de ley. Quien tiene menos de un año cobra la parte proporcional de
            // los días del primer tramo (Art. 197).
            'tramos' => [
                ['desde_anios' => 1, 'dias' => 15],
                ['desde_anios' => 3, 'dias' => 19],
                ['desde_anios' => 10, 'dias' => 21],
            ],
            'dias_proporcional' => 15,

            // Por confirmar: salario diario = mensual ÷ 30, y proporcional sobre 365 días.
            'dias_del_mes' => 30,
            'dias_del_anio' => 365,

            // Sin retención de renta hasta este monto, solo en 2026. Disposición transitoria
            // aprobada por la Asamblea Legislativa el 23/09/2026. Lo que pase de ahí no se
            // calcula: Decreto Ejecutivo 10, Art. 1 lit. g, usa otro procedimiento.
            'exento_renta' => '1500.00',
        ],
    ],

    // Ley Especial Quincena Veinticinco, Decreto Legislativo 499, Diario Oficial No. 8,
    // Tomo 450, 14/01/2026. Se paga íntegra y sin ISSS, AFP ni renta (Arts. 1 y 4).
    'quincena25' => [
        [
            // Obligatoria para el sector privado desde 2027 (Arts. 1 y 6).
            'vigente_desde' => '2027-01-01',

            // Del 15 al 25 de enero (Art. 1).
            'pago_desde' => '01-15',
            'pago_hasta' => '01-25',

            // 50 % del salario básico mensual, si es de US$1,500 o menos (Art. 2).
            'porcentaje' => '50.00',
            'salario_maximo' => '1500.00',

            // Por confirmar con el texto del Instructivo 01/2026 del MTPS (Diario Oficial del
            // 14/09/2026), que solo se pudo leer por la prensa: corte al 14 de enero y
            // proporcional = 50 % del salario ÷ 365 × días acumulados.
            'corte' => '01-14',
            'dias_del_anio' => 365,
        ],
    ],

    // Vacaciones anuales. Código de Trabajo Art. 177: después de un año de trabajo
    // continuo, 15 días con el salario ordinario más un recargo del 30 %. Se pagan desde el
    // mes del aniversario de la fecha de ingreso, y casi siempre en otro mes después.
    // Lo pagado fuera del sistema se anota en la ficha: «Vacaciones pagadas hasta».
    //
    // Confirmar con la contadora: los días, el recargo, el salario diario = mensual ÷ 30 y
    // el sueldo vigente a la fecha de pago. Que lleven ISSS, AFP y renta lo confirmó Melqui
    // (12/11/2026), y que se paguen además de las dos quincenas del mes.
    'vacaciones' => [
        'dias' => 15,
        'recargo' => '0.30',
        'dias_del_mes' => 30,

        // Melqui, 06/11/2026: llevan descuentos de ley. Quien va con descuentos paga ISSS,
        // AFP y renta como en una quincena; quien va sin descuentos cobra el bruto.
        'con_descuentos_ley' => true,
    ],

    // Lo que se paga cuando la persona SE VA, una sola vez: sale de su baja, como pago de
    // esa sola persona ({@see \App\Services\Planilla\IndemnizacionBaja}).
    //
    // Salario básico diario = sueldo mensual vigente a la fecha de baja ÷ `dias_del_mes`.
    // Años de servicio = del ingreso al día de baja, ese día incluido; la fracción son los
    // días que pasan del último aniversario ÷ `dias_del_anio`. El tope es en salarios
    // mínimos diarios vigentes a la fecha de baja, del sector de la empresa
    // ({@see \App\Services\Planilla\SalarioMinimo}). Se redondea una sola vez, al centavo,
    // la mitad hacia arriba.
    //
    // Confirmado por la contadora, 12/11/2026: todos los números de abajo, que el tope
    // también limite el mínimo de 15 días del despido y que no se sumen aguinaldo ni
    // vacaciones proporcionales. Que el día de baja cuente como trabajado lo confirmó
    // Melqui, 12/11/2026.
    'indemnizacion' => [
        // Despido sin causa. Código de Trabajo Art. 58: 30 días de salario básico por año
        // de servicio y proporcional por fracción, nunca menos de 15 días, y ningún
        // salario diario mayor que 4 salarios mínimos diarios para el cálculo.
        // Confirmado por la contadora, 12/11/2026.
        'despido' => [
            'dias_por_anio' => 30,
            'minimo_dias' => 15,
            'tope_minimos' => 4,
            'anios_minimos' => 0,
        ],

        // Renuncia voluntaria. Ley Reguladora de la Prestación Económica por Renuncia
        // Voluntaria: desde 2 años de servicio continuo, 15 días de salario básico por
        // año y proporcional por fracción, con un salario diario no mayor que 2 salarios
        // mínimos diarios. Confirmado por la contadora, 12/11/2026, con el mínimo de años y
        // el proporcional por fracción.
        'renuncia' => [
            'dias_por_anio' => 15,
            'minimo_dias' => 0,
            'tope_minimos' => 2,
            'anios_minimos' => 2,
        ],

        // Confirmado por la contadora, 12/11/2026.
        'dias_del_mes' => 30,
        'dias_del_anio' => 365,

        // Ni la indemnización ni la prestación por renuncia llevan ISSS, AFP ni renta.
        // Confirmado por la contadora, 12/11/2026. En true se calculan como en una quincena.
        'con_descuentos_ley' => false,
    ],

];
