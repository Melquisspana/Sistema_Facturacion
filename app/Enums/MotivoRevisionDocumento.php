<?php

namespace App\Enums;

/**
 * Por qué un documento de una salida quedó marcado como «requiere NC».
 *
 * Es un motivo OPERATIVO, dicho por quien anduvo la ruta: lo que se vio en la
 * sala. NO es un motivo fiscal ni el tipo de nota de crédito, y marcar esto no
 * crea ninguna NC: solo deja anotado que hay algo que revisar.
 *
 * El motivo es opcional a propósito. Obligar a clasificar en el momento hace que
 * la gente elija cualquiera con tal de seguir, y un motivo inventado es peor que
 * ninguno.
 */
enum MotivoRevisionDocumento: string
{
    case Averia = 'averia';
    case Devolucion = 'devolucion';
    case Faltante = 'faltante';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Averia => 'Avería',
            self::Devolucion => 'Devolución',
            self::Faltante => 'Faltante',
            self::Otro => 'Otro',
        };
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
