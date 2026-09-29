<?php

namespace App\Exceptions\Ppq;

use App\Services\Ppq\QuedanCallejaExporter;
use RuntimeException;

/**
 * El archivo de carga masiva de «Solicitud de Quedan» del portal de Calleja
 * ({@see QuedanCallejaExporter}) no se genera si algún CCF del lote no
 * tiene los cinco datos confiables que exige el formato, si su albarán no es de entrega, si
 * hay una contradicción de sala, o si dos CCF resuelven al mismo albarán.
 *
 * Se rechaza el archivo COMPLETO, nunca «lo que sí está»: omitir en silencio un CCF
 * incompleto dejaría un pronto pago sin reclamar sin que nadie lo note, y elegir entre dos
 * números de albarán duplicados no es una decisión que le corresponda al sistema.
 */
class ArchivoQuedanIncompletoException extends RuntimeException
{
    /**
     * @param  array<int, string>  $motivos  un motivo por documento (o por lote) en conflicto
     */
    public function __construct(public readonly array $motivos)
    {
        parent::__construct(
            'No se generó el archivo del portal de quedan: '.implode(' · ', $motivos)
        );
    }
}
