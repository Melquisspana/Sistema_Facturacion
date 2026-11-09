<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Plazo de transmisión del evento, desde el día local del sello (decisión 0005).
 * Los días inhábiles salen de {@see CalendarioInhabiles}: Configuración y, para un
 * año que no esté ahí, config/dte.php.
 */
final class PlazoInvalidacion
{
    public function __construct(
        private readonly CalendarioInhabiles $calendario = new CalendarioInhabiles,
    ) {}

    public function limite(TipoDte $tipo, string $fechaSello): Carbon
    {
        $fecha = $this->fecha($fechaSello);
        $inhabiles = $this->calendario->delAnio($this->anioCalendario($fechaSello))['fechas'] ?? [];
        if ($this->tresMeses($tipo)) {
            return HoraNegocio::finDelDiaUtc($fecha->addMonthsNoOverflow(3));
        }

        $dia = $fecha->startOfMonth()->addMonth();
        $habiles = 0;
        while (true) {
            if ($dia->isWeekday() && ! in_array($dia->format('Y-m-d'), $inhabiles, true)) {
                $habiles++;
            }
            if ($habiles === 10) {
                return HoraNegocio::finDelDiaUtc($dia);
            }
            $dia->addDay();
        }
    }

    public function calendarioCompleto(TipoDte $tipo, string $fechaSello): bool
    {
        return $this->tresMeses($tipo)
            || ($this->calendario->delAnio($this->anioCalendario($fechaSello))['completo'] ?? false);
    }

    /** Aviso para el mensaje y la pantalla cuando el plazo se calculó sin el calendario completo. */
    public function avisoCalendario(TipoDte $tipo, string $fechaSello): ?string
    {
        return $this->calendarioCompleto($tipo, $fechaSello)
            ? null
            : '(calculado sin el calendario completo de días inhábiles de '.$this->anioCalendario($fechaSello).'; cargalo en Configuración)';
    }

    public function anioCalendario(string $fechaSello): string
    {
        return $this->fecha($fechaSello)->startOfMonth()->addMonth()->format('Y');
    }

    private function tresMeses(TipoDte $tipo): bool
    {
        // Incluye el código 14 si se incorpora al enum; no habilita tipos ni motivos.
        return in_array($tipo->value, ['01', '11', '14'], true);
    }

    private function fecha(string $fecha): Carbon
    {
        if (! CalendarioInhabiles::esFecha($fecha)) {
            throw new InvalidArgumentException('Fecha inválida para el plazo de invalidación: '.$fecha.'. Se exige Y-m-d.');
        }

        return Carbon::createFromFormat('!Y-m-d', $fecha, HoraNegocio::zona());
    }
}
