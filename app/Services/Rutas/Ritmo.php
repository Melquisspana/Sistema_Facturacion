<?php

namespace App\Services\Rutas;

use Carbon\CarbonImmutable;

/**
 * Cada cuántos días compra una sala (o se va a una ruta), calculado por {@see RitmoPedidos}
 * con la regla de la sección 2 de docs/PLAN_TABLERO_RUTAS.md.
 *
 * `fechas` son los pedidos de la sala (o el comienzo de cada vuelta de la ruta), en orden.
 * `dias` es la mediana de los días entre uno y el siguiente; null mientras haya menos de
 * 3 fechas («sin ritmo todavía»).
 */
final class Ritmo
{
    public const CONFIABLE = 'confiable';

    public const APROXIMADO = 'aproximado';

    public const SIN_RITMO = 'sin_ritmo';

    public const AL_DIA = 'al_dia';

    public const PRONTO = 'pronto';

    public const ATRASADA = 'atrasada';

    /** Se avisa «toca pronto» desde estos días antes de la fecha esperada. */
    public const AVISO_DIAS = 2;

    /** El margen es el 20 % del ritmo, y como mínimo 2 días. */
    private const MARGEN = 0.2;

    private const MARGEN_MINIMO = 2;

    /** Solo se miran los últimos 10 intervalos: si la sala cambia de costumbre, el ritmo cambia con ella. */
    private const INTERVALOS = 10;

    /** Pasado este múltiplo del ritmo sin pedido, se pregunta «¿dejó de comprar?». */
    private const DEJO_DE_COMPRAR = 3;

    public readonly ?int $dias;

    /** @param  array<int, CarbonImmutable>  $fechas  en orden */
    public function __construct(public readonly array $fechas)
    {
        $this->dias = count($fechas) >= 3 ? self::mediana(self::intervalos($fechas)) : null;
    }

    /** 4 fechas o más: confiable; 3: aproximado; menos: sin ritmo. */
    public function confianza(): string
    {
        return match (true) {
            count($this->fechas) >= 4 => self::CONFIABLE,
            count($this->fechas) === 3 => self::APROXIMADO,
            default => self::SIN_RITMO,
        };
    }

    public function ultima(): ?CarbonImmutable
    {
        return $this->fechas === [] ? null : $this->fechas[array_key_last($this->fechas)];
    }

    /** Días desde el último pedido; null si nunca pidió. */
    public function diasDesde(CarbonImmutable $hoy): ?int
    {
        return $this->ultima() === null ? null : max(0, (int) $this->ultima()->diffInDays($hoy->startOfDay()));
    }

    public function estadoEn(CarbonImmutable $hoy): string
    {
        return $this->dias === null ? self::SIN_RITMO : self::estado($this->dias, (int) $this->diasDesde($hoy));
    }

    /**
     * ¿Pasó más de 3 veces su ritmo sin pedido? No se oculta: puede ser una sala perdida.
     * `$diasDesde` cuenta desde otra fecha (la última visita del tablero); si no, desde el último pedido.
     */
    public function dejoDeComprar(CarbonImmutable $hoy, ?int $diasDesde = null): bool
    {
        return $this->dias !== null && ($diasDesde ?? $this->diasDesde($hoy)) > self::DEJO_DE_COMPRAR * $this->dias;
    }

    public static function margen(int $ritmo): int
    {
        return max(self::MARGEN_MINIMO, (int) round($ritmo * self::MARGEN));
    }

    /**
     * Al día si faltan más de 2 días para la fecha esperada; toca pronto si faltan 2 o
     * menos o ya pasó pero dentro del margen; atrasada si pasó la fecha más el margen.
     * Vale igual para los días aproximados cargados a mano en una ruta sin ritmo.
     */
    public static function estado(int $ritmo, int $diasDesde): string
    {
        $faltan = $ritmo - $diasDesde;

        return match (true) {
            $faltan > self::AVISO_DIAS => self::AL_DIA,
            $faltan >= -self::margen($ritmo) => self::PRONTO,
            default => self::ATRASADA,
        };
    }

    /**
     * @param  array<int, CarbonImmutable>  $fechas
     * @return array<int, int>
     */
    private static function intervalos(array $fechas): array
    {
        $intervalos = [];
        for ($i = 1; $i < count($fechas); $i++) {
            $intervalos[] = (int) $fechas[$i - 1]->diffInDays($fechas[$i]);
        }

        return array_slice($intervalos, -self::INTERVALOS);
    }

    /**
     * El del medio, ordenados de menor a mayor; con dos en el medio, su promedio
     * redondeado. No se deja llevar por un mes raro.
     *
     * @param  array<int, int>  $valores  no vacío
     */
    private static function mediana(array $valores): int
    {
        sort($valores);
        $medio = intdiv(count($valores), 2);

        return count($valores) % 2 === 1
            ? $valores[$medio]
            : (int) round(($valores[$medio - 1] + $valores[$medio]) / 2);
    }
}
