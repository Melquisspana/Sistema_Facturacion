<?php

namespace App\Enums;

/**
 * Tamaño/clasificación del contribuyente (dato del cliente, no del documento).
 *
 * Regla de negocio: solo el contribuyente "grande" es agente de retención de
 * IVA. Pequeño y mediano no lo son. La retención nunca se pregunta manualmente:
 * se decide automáticamente desde aquí y, para CCF, según la base gravada.
 */
enum TamanioContribuyente: string
{
    case Pequeno = 'pequeno';
    case Mediano = 'mediano';
    case Grande = 'grande';

    public function label(): string
    {
        return match ($this) {
            self::Pequeno => 'Pequeño contribuyente',
            self::Mediano => 'Mediano contribuyente',
            self::Grande => 'Grande contribuyente',
        };
    }

    /** Solo el contribuyente grande es agente de retención. */
    public function esAgenteRetencion(): bool
    {
        return $this === self::Grande;
    }

    /** @return array<string, string> [valor => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
