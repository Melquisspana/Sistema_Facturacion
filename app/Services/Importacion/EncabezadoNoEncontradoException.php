<?php

namespace App\Services\Importacion;

use RuntimeException;

/**
 * Se lanza cuando un CSV no contiene una fila de encabezados reconocible con las
 * columnas requeridas (después de saltar títulos/filas decorativas).
 */
class EncabezadoNoEncontradoException extends RuntimeException
{
}
