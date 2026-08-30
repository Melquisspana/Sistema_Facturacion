<?php

namespace App\Exceptions\Rutas;

use RuntimeException;

/**
 * Se intentó meter en una salida de ruta un documento que no está fiscalmente vigente:
 * un borrador, un rechazado, un invalidado, uno archivado o uno del ambiente de PRUEBAS.
 *
 * Hasta ahora Rutas solo comprobaba «tipo 03 y no archivado», así que cualquiera de esos
 * podía viajar en una salida y, peor, arrastrar consigo su número de control —que en
 * pruebas y en producción puede ser el mismo— y bloquear al documento real.
 *
 * Lleva el motivo dentro porque quien lo lee es la persona que está armando la salida:
 * «no se puede» sin decir por qué la obliga a adivinar si el documento está mal, si le
 * falta un paso o si el sistema se equivocó.
 */
class DocumentoNoVigenteException extends RuntimeException
{
    public function __construct(
        public readonly ?string $numeroControl,
        public readonly string $motivo,
    ) {
        parent::__construct(sprintf(
            'El documento %s no se puede llevar en una salida de ruta. %s',
            $numeroControl ?? 'indicado',
            $motivo,
        ));
    }
}
