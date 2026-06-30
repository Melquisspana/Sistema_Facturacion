<?php

namespace App\Enums;

/**
 * Motivo de la anulación INTERNA/preliminar de un documento. No es el catálogo
 * oficial de invalidación del MH (eso se mapeará cuando se confirme el schema).
 */
enum MotivoAnulacion: string
{
    case ErrorDatosCliente = 'error_datos_cliente';
    case ErrorProductos = 'error_productos';
    case ErrorMonto = 'error_monto';
    case DocumentoDuplicado = 'documento_duplicado';
    case NotaCreditoIncorrecta = 'nota_credito_incorrecta';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::ErrorDatosCliente => 'Error en datos del cliente',
            self::ErrorProductos => 'Error en productos',
            self::ErrorMonto => 'Error en monto',
            self::DocumentoDuplicado => 'Documento duplicado',
            self::NotaCreditoIncorrecta => 'Nota de crédito incorrecta',
            self::Otro => 'Otro',
        };
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
