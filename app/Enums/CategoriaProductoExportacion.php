<?php

namespace App\Enums;

/**
 * Sección del catálogo de exportación. Ordena la pantalla y la lista de precios
 * que se le comparte al cliente; no viaja a la factura ni a la lista de empaque.
 */
enum CategoriaProductoExportacion: string
{
    case Semillas = 'semillas';
    case Tradicionales = 'tradicionales';
    case Caramelos = 'caramelos';
    case Paletas = 'paletas';
    case Chicles = 'chicles';
    case Exhibidores = 'exhibidores';

    public function label(): string
    {
        return match ($this) {
            self::Semillas => 'Semillas y maní',
            self::Tradicionales => 'Dulces tradicionales',
            self::Caramelos => 'Caramelos',
            self::Paletas => 'Paletas y nougat',
            self::Chicles => 'Chicles y chocolate',
            self::Exhibidores => 'Exhibidores',
        };
    }

    /** Posición en la pantalla: el orden de declaración. */
    public function orden(): int
    {
        return array_search($this, self::cases(), true);
    }
}
