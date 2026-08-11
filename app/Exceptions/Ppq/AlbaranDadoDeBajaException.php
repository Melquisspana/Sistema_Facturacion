<?php

namespace App\Exceptions\Ppq;

use RuntimeException;

/**
 * Ya existe un albarán con el mismo número + OC pero está DADO DE BAJA (soft delete).
 *
 * El índice único `ppq_albaran_oc_unico` no distingue borrados, así que insertar de nuevo
 * reventaría con una violación de integridad. Y resucitarlo por su cuenta tampoco es
 * opción: si alguien lo dio de baja fue a propósito.
 *
 * Se lanza para que cada vía decida sin romper: la pantalla muestra el error y pide
 * revisarlo a mano; la sincronización lo cuenta como omitido y sigue con los demás correos.
 */
class AlbaranDadoDeBajaException extends RuntimeException
{
    public function __construct(
        public readonly string $numeroAlbaran,
        public readonly ?string $numeroOrdenCompra,
        public readonly int $albaranId,
    ) {
        parent::__construct(sprintf(
            'El albarán %s (OC %s) está dado de baja; revisalo y restauralo a mano antes de volver a registrarlo.',
            $numeroAlbaran,
            $numeroOrdenCompra ?? 'sin OC',
        ));
    }
}
