<?php

namespace App\Enums;

/**
 * Clasificación del cliente (receptor). Define qué documento se le emite y, por
 * tanto, qué campos fiscales son obligatorios.
 *
 * - ConsumidorFinal: nacional, recibe Factura (01). NRC no requerido.
 * - Contribuyente:    nacional, recibe Crédito Fiscal (03). NIT + NRC requeridos.
 * - Exportacion:      receptor en el extranjero (FEX, 11). NRC no aplica.
 */
enum TipoCliente: string
{
    case ConsumidorFinal = 'consumidor_final';
    case Contribuyente = 'contribuyente';
    case Exportacion = 'exportacion';

    public function label(): string
    {
        return match ($this) {
            self::ConsumidorFinal => 'Consumidor final',
            self::Contribuyente => 'Contribuyente',
            self::Exportacion => 'Exportación',
        };
    }

    /** ¿Es un receptor nacional (El Salvador)? */
    public function esNacional(): bool
    {
        return $this === self::ConsumidorFinal || $this === self::Contribuyente;
    }

    public function esExportacion(): bool
    {
        return $this === self::Exportacion;
    }

    /** El NRC solo es obligatorio para el contribuyente nacional. */
    public function requiereNrc(): bool
    {
        return $this === self::Contribuyente;
    }

    /** Departamento y municipio nacionales se exigen al contribuyente. */
    public function requiereUbicacionNacional(): bool
    {
        return $this === self::Contribuyente;
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
