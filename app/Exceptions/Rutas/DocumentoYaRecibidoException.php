<?php

namespace App\Exceptions\Rutas;

use App\Models\CustodiaDocumentoEvento;
use RuntimeException;

/**
 * Ese CCF físico ya estaba registrado como recibido.
 *
 * Es la respuesta correcta —no un fallo— cuando alguien escanea dos veces el mismo papel, y
 * también cuando dos personas confirman a la vez que llegó. En el segundo caso quien la
 * lanza es el índice único `custodia_recepcion_unica`, que es el que de verdad garantiza que
 * no existan dos recepciones vigentes: la comprobación previa en PHP puede perder la carrera,
 * el índice no.
 *
 * Lleva dentro el evento anterior para poder decir CUÁNDO y QUIÉN lo recibió, en vez de un
 * «no se pudo» que obliga a ir a buscarlo.
 */
class DocumentoYaRecibidoException extends RuntimeException
{
    public function __construct(
        public readonly string $numeroControl,
        public readonly ?CustodiaDocumentoEvento $recepcionAnterior = null,
    ) {
        $cuando = $recepcionAnterior?->ocurrido_en?->translatedFormat('d M Y H:i');
        $quien = $recepcionAnterior?->registradoPor?->name;

        parent::__construct(sprintf(
            'El documento %s ya estaba recibido%s%s. No se registró de nuevo.',
            $numeroControl,
            $cuando ? ' desde el '.$cuando : '',
            $quien ? ' (lo registró '.$quien.')' : '',
        ));
    }
}
