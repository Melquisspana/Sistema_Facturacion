<?php

namespace App\Exceptions\Ppq;

use App\Models\PpqConciliacion;
use RuntimeException;

/**
 * Ese mismo archivo de pagos ya se procesó en este lote.
 *
 * La identidad del archivo es su SHA-256, no su nombre: el mismo TXT renombrado sigue
 * siendo el mismo TXT, y descargarlo dos veces del correo del cliente produce byte por
 * byte el mismo contenido. Volver a aplicarlo no cambiaría nada —los renglones ya están
 * en ese estado— pero sí ensuciaría la bitácora con una corrida que no hizo nada, y haría
 * imposible distinguir «lo subieron dos veces» de «hubo dos pagos».
 *
 * Se lanza en vez de dejar que salte el índice único `ppq_conciliacion_lote_archivo_unico`,
 * para poder decir CUÁNDO y QUIÉN lo procesó la primera vez en lugar de un error de SQL.
 * El índice sigue estando: es el que gana la carrera entre dos pestañas abiertas.
 */
class ConciliacionYaProcesadaException extends RuntimeException
{
    public function __construct(public readonly PpqConciliacion $anterior)
    {
        parent::__construct(sprintf(
            'Ese archivo ya se procesó en este lote el %s%s. No se volvió a aplicar y no cambió nada.',
            $anterior->created_at?->translatedFormat('d M Y H:i') ?? 'antes',
            $anterior->usuario?->name ? ' ('.$anterior->usuario->name.')' : '',
        ));
    }
}
