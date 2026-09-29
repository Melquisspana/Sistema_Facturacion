<?php

namespace App\Services\Planilla;

use Carbon\CarbonImmutable;

/**
 * Los períodos de planilla: semanal, quincenal y mensual.
 *
 * OJO con «quincenal», porque NO es lo mismo que en las repeticiones de Gastos. Allá
 * son dos FECHAS DE VENCIMIENTO dentro del mes; acá son dos TRAMOS DE TIEMPO
 * trabajados: del 1 al 15 y del 16 al último día. Son dos cosas distintas que se
 * llaman igual, y por eso esta clase existe aparte en vez de reusar aquella: forzar
 * una sola abstracción haría que cambiar el vencimiento de un alquiler moviera el
 * tramo trabajado de una quincena.
 *
 * La semana va de LUNES A DOMINGO (ISO) y se identifica por su semana ISO, así que la
 * semana que cruza el fin de año no se parte en dos períodos.
 */
final class PeriodoPlanilla
{
    /**
     * El período que contiene a una fecha.
     *
     * @return array{periodo: string, desde: string, hasta: string, etiqueta: string}
     */
    public function para(string $tipo, CarbonImmutable $fecha): array
    {
        $fecha = $fecha->startOfDay();

        return match ($tipo) {
            'semanal' => $this->semanal($fecha),
            'quincenal' => $this->quincenal($fecha),
            default => $this->mensual($fecha),
        };
    }

    /** @return array{periodo: string, desde: string, hasta: string, etiqueta: string} */
    private function semanal(CarbonImmutable $f): array
    {
        $desde = $f->startOfWeek();
        $hasta = $desde->addDays(6);

        return [
            'periodo' => $desde->format('o-\WW'),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'etiqueta' => 'Semana '.$desde->format('W').' de '.$desde->format('o')
                .' ('.$desde->format('d/m').' al '.$hasta->format('d/m').')',
        ];
    }

    /** @return array{periodo: string, desde: string, hasta: string, etiqueta: string} */
    private function quincenal(CarbonImmutable $f): array
    {
        $primera = $f->day <= 15;
        $desde = $primera ? $f->startOfMonth() : $f->startOfMonth()->addDays(15);
        $hasta = $primera ? $f->startOfMonth()->addDays(14) : $f->endOfMonth()->startOfDay();

        return [
            'periodo' => $f->format('Y-m').($primera ? '-Q1' : '-Q2'),
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'etiqueta' => ($primera ? 'Primera' : 'Segunda').' quincena de '.$this->mesEnPalabras($f)
                .' ('.$desde->format('d').' al '.$hasta->format('d').')',
        ];
    }

    /** @return array{periodo: string, desde: string, hasta: string, etiqueta: string} */
    private function mensual(CarbonImmutable $f): array
    {
        return [
            'periodo' => $f->format('Y-m'),
            'desde' => $f->startOfMonth()->toDateString(),
            'hasta' => $f->endOfMonth()->startOfDay()->toDateString(),
            'etiqueta' => 'Mes de '.$this->mesEnPalabras($f),
        ];
    }

    public function mesEnPalabras(CarbonImmutable $f): string
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return ($meses[$f->month] ?? '').' de '.$f->year;
    }

    /**
     * Los períodos que conviene ofrecer en el formulario: el actual y unos cuantos
     * hacia atrás. No se ofrecen futuros: una planilla se prepara sobre tiempo ya
     * trabajado, no sobre tiempo por trabajar.
     *
     * @return array<int, array{periodo: string, desde: string, hasta: string, etiqueta: string}>
     */
    public function recientes(string $tipo, CarbonImmutable $hoy, int $cuantos = 6): array
    {
        $salida = [];
        $cursor = $hoy;

        for ($i = 0; $i < $cuantos; $i++) {
            $p = $this->para($tipo, $cursor);
            $salida[$p['periodo']] = $p;

            // Se retrocede un día antes del inicio del período: así se cae siempre en el
            // anterior, sin tener que saber cuánto dura cada tipo.
            $cursor = CarbonImmutable::parse($p['desde'])->subDay();
        }

        return array_values($salida);
    }
}
