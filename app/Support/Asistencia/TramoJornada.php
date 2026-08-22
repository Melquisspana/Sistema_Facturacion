<?php

namespace App\Support\Asistencia;

use App\Models\Asistencia\AsistenciaMarcacion;

/**
 * Un tramo de presencia: una entrada y —si la hay— su salida.
 *
 * Existe porque una jornada NO es «primera entrada hasta última salida». Quien
 * entra a las 07:00, sale a las 12:00, vuelve a las 13:00 y se va a las 16:00
 * trabajó **8 horas, no 9**: la hora de almuerzo está en medio y restarla exige
 * saber que hubo dos tramos, no uno.
 *
 * El módulo no sabe qué es un almuerzo —ni tiene por qué—: solo suma los tramos
 * que encuentra.
 *
 * ─────────────────────────── Tramos abiertos ───────────────────────────
 *
 * `$salida` puede ser null: alguien entró y no marcó la salida. Un tramo abierto
 * **no aporta tiempo**. Cerrarlo con «ahora» o con el final del día serían dos
 * formas distintas de inventar una hora que nadie marcó, y las dos acabarían en
 * una planilla.
 *
 * También existe el caso contrario —una salida sin entrada previa—, que llega
 * solo de correcciones manuales. Se representa con `$entrada = null`.
 */
final class TramoJornada
{
    private function __construct(
        public readonly ?AsistenciaMarcacion $entrada,
        public readonly ?AsistenciaMarcacion $salida,
    ) {}

    public static function cerrado(AsistenciaMarcacion $entrada, AsistenciaMarcacion $salida): self
    {
        return new self($entrada, $salida);
    }

    /** Entró y no marcó salida. */
    public static function abierto(AsistenciaMarcacion $entrada): self
    {
        return new self($entrada, null);
    }

    /** Salió sin que constara una entrada. Solo puede venir de una corrección manual. */
    public static function salidaHuerfana(AsistenciaMarcacion $salida): self
    {
        return new self(null, $salida);
    }

    /**
     * `estaCerrado` y no `cerrado` porque `cerrado()` ya es el constructor
     * estático que crea uno. Dos métodos con el mismo nombre no compilan, y de los
     * dos el que tiene que leerse bien es el que se usa al construir.
     */
    public function estaCerrado(): bool
    {
        return $this->entrada !== null && $this->salida !== null;
    }

    /**
     * Segundos de presencia. `null` cuando el tramo no está cerrado: no es cero
     * —cero significaría «estuvo y no duró nada»— sino «no se puede saber».
     */
    public function segundos(): ?int
    {
        if (! $this->estaCerrado()) {
            return null;
        }

        // Resta de timestamps absolutos: no depende de la zona con la que Eloquent
        // hidrató las fechas. Es el mismo criterio de la ventana de cortesía.
        $segundos = $this->salida->marcado_at->getTimestamp() - $this->entrada->marcado_at->getTimestamp();

        // Una salida anterior a su entrada solo puede venir de datos corregidos a
        // mano. Se devuelve 0 en vez de un negativo que restaría de la suma del día
        // y dejaría un total imposible de explicar.
        return max(0, $segundos);
    }
}
