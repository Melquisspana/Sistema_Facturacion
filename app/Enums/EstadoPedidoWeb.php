<?php

namespace App\Enums;

enum EstadoPedidoWeb: string
{
    case Importado = 'importado';
    case ParaRevisar = 'para_revisar';
    // Factura automática (ADR 0011): ya tiene su borrador y se está emitiendo.
    case Facturando = 'facturando';
    // Hacienda aceptó la factura; quedan el correo y el aviso a la página si faltan.
    case Facturado = 'facturado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Importado => 'Importado',
            self::Facturando => 'Facturando',
            self::Facturado => 'Facturado',
            self::ParaRevisar => 'Para revisar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Importado => 'gray',
            self::Facturando => 'indigo',
            self::Facturado => 'green',
            self::ParaRevisar => 'red',
        };
    }
}
