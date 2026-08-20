<?php

namespace App\Ajustes\Definicion;

use App\Support\Dinero;

/**
 * Tipo de dato de un ajuste. Gobierna la conversión texto ↔ PHP, que es
 * DETERMINISTA a propósito: el valor viaja siempre como texto (columna `valor`,
 * .env, config) y volver a convertirlo no puede depender de las reglas laxas de
 * PHP. En particular:
 *
 *  - "false" NO es true. El cast nativo de PHP ((bool) 'false' === true) es
 *    exactamente el error que este enum existe para impedir.
 *  - Los decimales NO pasan por float. Se conservan como cadena y se operan con
 *    {@see Dinero} (bcmath). Un porcentaje de IVA o un monto fiscal
 *    no puede perder precisión por un round-trip a float.
 */
enum TipoAjuste: string
{
    case Texto = 'texto';
    case Entero = 'entero';
    case Decimal = 'decimal';
    case Booleano = 'booleano';
    case Email = 'email';
    case Enumerado = 'enumerado';
    case Secreto = 'secreto';
    case Lista = 'lista';

    /** ¿Este tipo se guarda cifrado y NUNCA vuelve al navegador? */
    public function esSecreto(): bool
    {
        return $this === self::Secreto;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Texto => 'Texto',
            self::Entero => 'Número entero',
            self::Decimal => 'Número decimal',
            self::Booleano => 'Sí / No',
            self::Email => 'Correo electrónico',
            self::Enumerado => 'Opción de una lista',
            self::Secreto => 'Secreto',
            self::Lista => 'Lista de valores',
        };
    }
}
