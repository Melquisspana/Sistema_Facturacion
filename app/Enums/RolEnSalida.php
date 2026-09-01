<?php

namespace App\Enums;

/**
 * Con qué papel va una persona en UNA salida concreta.
 *
 * Se asigna POR SALIDA y nunca de forma permanente: la misma persona es responsable el
 * lunes en San Miguel y acompañante el jueves en Sonsonate. Guardarlo en la persona
 * —«fulano es el responsable»— obligaría a editar el catálogo cada vez que cambia quién
 * va a cargo, y perdería el historial de quién respondió por cada viaje.
 *
 * Distinto de {@see FuncionPersonalRuta}, que dice qué SABE hacer alguien. Una persona
 * puede tener la función `responsable_salida` y aun así ir de acompañante.
 */
enum RolEnSalida: string
{
    /**
     * Queda a cargo del viaje. Al volver reúne los documentos de sus compañeros, así que
     * es a quien se le reclama el papel que falta. Como mucho uno por salida.
     */
    case Responsable = 'responsable';

    /** Va en la salida y puede llevar documentos, pero no responde por el grupo. */
    case Acompanante = 'acompanante';

    public function label(): string
    {
        return match ($this) {
            self::Responsable => 'Responsable',
            self::Acompanante => 'Acompañante',
        };
    }

    public function esResponsable(): bool
    {
        return $this === self::Responsable;
    }

    /** Clases del badge. Sin variantes `dark:`: ver la nota en {@see FuncionPersonalRuta::clase()}. */
    public function clase(): string
    {
        return match ($this) {
            self::Responsable => 'bg-amber-100 text-amber-800',
            self::Acompanante => 'bg-gray-100 text-gray-600',
        };
    }

    /** @return array<string, string> */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
