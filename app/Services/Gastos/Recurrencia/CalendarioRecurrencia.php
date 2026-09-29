<?php

namespace App\Services\Gastos\Recurrencia;

use Carbon\CarbonImmutable;

/**
 * La aritmética de fechas de las recurrencias. Sin base de datos y sin modelos: da
 * períodos y vencimientos, y nada más. Está aparte justamente para poder probar el
 * caso feo —31 de febrero, 29 de febrero en año no bisiesto, semana ISO a caballo
 * entre dos años— sin montar una regla ni un usuario.
 *
 * ───────────────────────────── La clave del período ─────────────────────────────
 *
 * Cada ocurrencia se identifica por una CLAVE LÓGICA, no por su fecha:
 *
 *   semanal    2026-W12      semana ISO
 *   quincenal  2026-03-Q1    primera o segunda quincena del mes
 *   mensual    2026-03       el mes
 *   anual      2026          el año
 *
 * Es la columna que lleva el índice único junto a la regla, así que es LA garantía
 * de «una obligación por período». Que sea lógica y no una fecha es lo que hace que
 * corregir el día de vencimiento —del 5 al 10— no reabra un mes ya generado: sigue
 * siendo «2026-03».
 *
 * La semana usa año ISO (`o`) y no el año natural: el 31 de diciembre de 2025 cae en
 * la semana `2026-W01`. Usar el año natural crearía dos períodos distintos para la
 * misma semana y generaría la obligación dos veces.
 *
 * ──────────────────────── Días que no existen en el mes ────────────────────────
 *
 * Un vencimiento «el 31» en un mes de 30 días cae el 30, y el 29 de febrero de un
 * año no bisiesto cae el 28. Se recorta al ÚLTIMO DÍA DEL MES, nunca se empuja al
 * mes siguiente: pasar el alquiler del 31 de abril al 1 de mayo movería la deuda de
 * mes y descuadraría cualquier informe por período.
 *
 * La política se muestra al configurar la regla ({@see POLITICA_DIAS}) y queda
 * guardada en cada ocurrencia por su fecha ya resuelta.
 */
final class CalendarioRecurrencia
{
    public const FRECUENCIAS = [
        'semanal' => 'Semanal',
        'quincenal' => 'Quincenal (dos fechas del mes)',
        'mensual' => 'Mensual',
        'anual' => 'Anual',
    ];

    public const DIAS_SEMANA = [
        1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
        5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
    ];

    /**
     * «Último día del mes» se codifica como 31.
     *
     * No es un atajo: 31 es, por definición, el último día de cualquier mes que lo
     * tenga, y en los que no —30, 29 o 28 días— la política de recorte lo lleva
     * igualmente al último. Pedir «el 31» y pedir «el último día» son la misma
     * instrucción, así que no hacen falta dos codificaciones que después habría que
     * mantener sincronizadas.
     *
     * Lo que sí hace falta es que la pantalla lo DIGA con esas palabras, y no que el
     * operador tenga que deducirlo escribiendo 31 en una casilla numérica. Por eso
     * {@see diasDelMes()} ofrece la opción con su nombre.
     */
    public const ULTIMO_DIA = 31;

    public const POLITICA_DIAS = 'Si el día elegido no existe en el mes, el vencimiento se corre al último día '
        .'de ese mes (el 30 en un mes de 30 cae el 30; el 29 de febrero cae el 28 en año no bisiesto). '
        .'Nunca pasa al mes siguiente: la deuda no cambia de período.';

    /**
     * QUÉ ES «QUINCENAL» ACÁ, y qué no es.
     *
     * Son DOS FECHAS DEL MES, las dos configurables —típicamente el 15 y el último
     * día—. NO es «cada 14 días» ni «cada dos semanas»: eso es otra cosa y no está
     * implementado.
     *
     * La diferencia no es de matiz. Dos fechas del mes están ancladas al mes, así que
     * cada período tiene una clave lógica estable (`2026-03-Q1`, `2026-03-Q2`) y
     * siempre hay exactamente dos por mes. Cada 14 días no se ancla a nada: deriva, de
     * modo que unos meses caen dos vencimientos y otros tres, y «la quincena de marzo»
     * deja de significar algo. Como la clave lógica es lo que garantiza «una obligación
     * por período», mezclarlas rompería el candado contra duplicados.
     */
    public const POLITICA_QUINCENAL = 'Quincenal son DOS FECHAS DEL MES, las dos configurables '
        .'(por ejemplo el 15 y el último día). No es «cada 14 días»: siempre son dos vencimientos por mes, '
        .'anclados al mes, y por eso cada uno tiene su período propio (Q1 y Q2).';

    /**
     * Opciones de día del mes para los selectores, con el último día por su nombre.
     *
     * @return array<int, string>
     */
    public static function diasDelMes(): array
    {
        $dias = [];

        for ($d = 1; $d <= 30; $d++) {
            $dias[$d] = 'Día '.$d;
        }

        $dias[self::ULTIMO_DIA] = 'Último día del mes';

        return $dias;
    }

    /**
     * Períodos de una regla cuyo VENCIMIENTO cae dentro de la ventana, en orden.
     *
     * Se filtra por la fecha de vencimiento y no por el inicio del período, porque es
     * el vencimiento lo que decide si la obligación existe todavía: una regla que
     * termina el 20 de marzo no debe generar la cuota que vence el 31.
     *
     * @param  array{frecuencia: string, dia_semana?: ?int, dia_mes?: ?int, dia_mes_2?: ?int, mes?: ?int}  $regla
     * @return array<int, array{periodo: string, vence: CarbonImmutable}>
     */
    public function periodos(array $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($desde->gt($hasta)) {
            return [];
        }

        $candidatos = match ($regla['frecuencia']) {
            'semanal' => $this->semanales($regla, $desde, $hasta),
            'quincenal' => $this->quincenales($regla, $desde, $hasta),
            'mensual' => $this->mensuales($regla, $desde, $hasta),
            'anual' => $this->anuales($regla, $desde, $hasta),
            default => [],
        };

        $dentro = array_values(array_filter(
            $candidatos,
            fn (array $c) => $c['vence']->gte($desde->startOfDay()) && $c['vence']->lte($hasta->startOfDay()),
        ));

        usort($dentro, fn ($a, $b) => $a['vence'] <=> $b['vence']);

        return $dentro;
    }

    /** El período lógico al que pertenece una fecha, con la frecuencia dada. */
    public function periodoDe(string $frecuencia, CarbonImmutable $fecha): string
    {
        return match ($frecuencia) {
            'semanal' => $fecha->format('o-\WW'),
            'quincenal' => $fecha->format('Y-m').'-Q?',
            'mensual' => $fecha->format('Y-m'),
            'anual' => $fecha->format('Y'),
            default => $fecha->toDateString(),
        };
    }

    /**
     * Descripción en castellano de cuándo vence. Va en la pantalla de la regla y en
     * la confirmación: quien la configura tiene que poder leer lo que va a pasar sin
     * interpretar campos sueltos.
     *
     * @param  array{frecuencia: string, dia_semana?: ?int, dia_mes?: ?int, dia_mes_2?: ?int, mes?: ?int}  $regla
     */
    public function enPalabras(array $regla): string
    {
        // Una regla «Por completar» todavía no tiene día, y decir «el día » —con el
        // hueco al final— parece un error de programa. Se dice lo que pasa.
        $ordinal = fn (?int $d) => match (true) {
            $d === null => 'falta el día',
            $d === self::ULTIMO_DIA => 'el último día',
            default => 'el día '.$d,
        };

        return match ($regla['frecuencia']) {
            'semanal' => 'Cada semana, el '.mb_strtolower(self::DIAS_SEMANA[$regla['dia_semana']] ?? ''),
            // «Dos fechas del mes» y no «dos veces al mes»: lo segundo se puede leer
            // como «cada dos semanas», que es justo lo que esto NO es.
            'quincenal' => 'Dos fechas del mes: '.$ordinal($regla['dia_mes']).' y '.$ordinal($regla['dia_mes_2']),
            'mensual' => 'Cada mes, '.$ordinal($regla['dia_mes']),
            'anual' => 'Cada año, '.$ordinal($regla['dia_mes'])
                .($regla['mes'] === null ? ' y el mes' : ' de '.$this->nombreMes($regla['mes'])),
            default => '',
        };
    }

    /**
     * El día pedido, recortado al último del mes cuando no existe.
     *
     * Recibe el ancla del mes y el día deseado; devuelve la fecha resuelta. Es el
     * único sitio donde se decide esto.
     */
    public function diaDelMes(CarbonImmutable $mes, int $dia): CarbonImmutable
    {
        $primero = $mes->startOfMonth();

        return $primero->setDay(min($dia, $primero->daysInMonth));
    }

    /** @return array<int, array{periodo: string, vence: CarbonImmutable}> */
    private function semanales(array $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $dia = (int) ($regla['dia_semana'] ?? 1);
        $salida = [];

        // Se arranca una semana antes y se termina una después: el vencimiento de una
        // semana puede caer fuera del rango aunque su lunes esté dentro, y al revés.
        $cursor = $desde->startOfWeek()->subWeek();
        $limite = $hasta->startOfWeek()->addWeek();

        while ($cursor->lte($limite)) {
            $vence = $cursor->startOfWeek()->addDays($dia - 1);
            $salida[] = ['periodo' => $vence->format('o-\WW'), 'vence' => $vence];
            $cursor = $cursor->addWeek();
        }

        return $salida;
    }

    /** @return array<int, array{periodo: string, vence: CarbonImmutable}> */
    private function quincenales(array $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $primero = (int) ($regla['dia_mes'] ?? 15);
        $segundo = (int) ($regla['dia_mes_2'] ?? 31);
        $salida = [];

        foreach ($this->meses($desde, $hasta) as $mes) {
            $salida[] = ['periodo' => $mes->format('Y-m').'-Q1', 'vence' => $this->diaDelMes($mes, $primero)];
            $salida[] = ['periodo' => $mes->format('Y-m').'-Q2', 'vence' => $this->diaDelMes($mes, $segundo)];
        }

        return $salida;
    }

    /** @return array<int, array{periodo: string, vence: CarbonImmutable}> */
    private function mensuales(array $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $dia = (int) ($regla['dia_mes'] ?? 1);

        return array_map(
            fn (CarbonImmutable $mes) => ['periodo' => $mes->format('Y-m'), 'vence' => $this->diaDelMes($mes, $dia)],
            $this->meses($desde, $hasta),
        );
    }

    /** @return array<int, array{periodo: string, vence: CarbonImmutable}> */
    private function anuales(array $regla, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $dia = (int) ($regla['dia_mes'] ?? 1);
        $mes = (int) ($regla['mes'] ?? 1);
        $salida = [];

        for ($ano = $desde->year - 1; $ano <= $hasta->year + 1; $ano++) {
            $ancla = CarbonImmutable::create($ano, $mes, 1)->startOfDay();
            $salida[] = ['periodo' => (string) $ano, 'vence' => $this->diaDelMes($ancla, $dia)];
        }

        return $salida;
    }

    /**
     * Primeros de mes que pueden aportar un vencimiento a la ventana. Un mes de más a
     * cada lado, por la misma razón que en las semanas.
     *
     * @return array<int, CarbonImmutable>
     */
    private function meses(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $meses = [];
        $cursor = $desde->startOfMonth()->subMonth();
        $limite = $hasta->startOfMonth()->addMonth();

        while ($cursor->lte($limite)) {
            $meses[] = $cursor;
            $cursor = $cursor->addMonth();
        }

        return $meses;
    }

    /**
     * Público porque la pantalla que completa una regla anual necesita nombrar los
     * meses, y no tiene sentido que mantenga su propia lista: dos listas de los mismos
     * doce nombres terminan discrepando en alguna tilde.
     */
    public function nombreMes(int $mes): string
    {
        return [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ][$mes] ?? '';
    }
}
