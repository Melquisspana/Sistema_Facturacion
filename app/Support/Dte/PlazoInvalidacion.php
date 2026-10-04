<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Plazo de transmisión del evento, desde el día local del sello (decisión 0005). */
final class PlazoInvalidacion
{
    public function limite(TipoDte $tipo, string $fechaSello): Carbon
    {
        $fecha = $this->fecha($fechaSello);
        $calendario = $this->calendario();
        if ($this->tresMeses($tipo)) {
            return HoraNegocio::finDelDiaUtc($fecha->addMonthsNoOverflow(3));
        }

        $dia = $fecha->startOfMonth()->addMonth();
        $inhabiles = $calendario[$dia->format('Y')] ?? [];
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
            || array_key_exists($this->anioCalendario($fechaSello), $this->calendario());
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
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)
            || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            throw new InvalidArgumentException('Fecha inválida para el plazo de invalidación: '.$fecha.'. Se exige Y-m-d.');
        }

        return Carbon::createFromFormat('!Y-m-d', $fecha, HoraNegocio::zona());
    }

    /** @return array<int|string, array<int, string>> */
    private function calendario(): array
    {
        $calendario = config('dte.invalidacion.dias_inhabiles', []);
        if (! is_array($calendario)) {
            throw new InvalidArgumentException('Configuración inválida: dte.invalidacion.dias_inhabiles debe ser un arreglo por año.');
        }
        foreach ($calendario as $anio => $fechas) {
            if (! preg_match('/^\d{4}$/D', (string) $anio) || ! is_array($fechas) || ! array_is_list($fechas)) {
                throw new InvalidArgumentException('Configuración inválida: dte.invalidacion.dias_inhabiles.'.$anio.' debe ser una lista de fechas Y-m-d.');
            }
            foreach ($fechas as $fecha) {
                try {
                    if (! is_string($fecha) || $this->fecha($fecha)->format('Y') !== (string) $anio) {
                        throw new InvalidArgumentException;
                    }
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Configuración inválida: fecha en dte.invalidacion.dias_inhabiles.'.$anio.'. Se exige Y-m-d del mismo año.', previous: $e);
                }
            }
        }

        return $calendario;
    }
}
