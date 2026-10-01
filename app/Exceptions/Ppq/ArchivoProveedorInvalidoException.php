<?php

namespace App\Exceptions\Ppq;

use App\Services\Ppq\ValidadorCodigoProveedorTxt;
use RuntimeException;

/**
 * Una fila del TXT de pagos trae un código de proveedor distinto del esperado (o
 * vacío), o falta configurar el proveedor. Una fila con otro código no es un pago
 * suyo, aunque su número de documento coincida con uno local.
 *
 * Se rechaza el archivo ENTERO, igual criterio que
 * {@see ArchivoConciliacionInconsistenteException}: aplicar las filas «buenas» y
 * descartar las demás dejaría un archivo a medio procesar sin que nadie lo haya
 * decidido, y coincidir de número no prueba que sea el mismo emisor.
 *
 * @see ValidadorCodigoProveedorTxt
 */
class ArchivoProveedorInvalidoException extends RuntimeException
{
    public function __construct(
        public readonly string $esperado,
        public readonly string $encontrado,
        public readonly int $linea,
    ) {
        if ($esperado === '') {
            parent::__construct('Falta configurar el código de proveedor (PPQ_CODIGO_PROVEEDOR)');

            return;
        }

        parent::__construct(sprintf(
            'El archivo trae un código de proveedor distinto de %s en la línea %d (código %s). '
            .'No se aplicó nada. Verificá que corresponda a este proveedor.',
            $esperado,
            $linea,
            $encontrado === '' ? 'vacío' : $encontrado,
        ));
    }
}
