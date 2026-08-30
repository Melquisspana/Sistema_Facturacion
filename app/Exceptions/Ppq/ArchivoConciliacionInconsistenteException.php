<?php

namespace App\Exceptions\Ppq;

use RuntimeException;

/**
 * El archivo de pagos se contradice a sí mismo: el mismo documento aparece más de una vez
 * con datos DISTINTOS (tipo, fecha o importe).
 *
 * ─────────────────────────── Por qué se rechaza entero ───────────────────────────
 *
 * Indexar las filas por número de documento y quedarse con la última —que es lo que hacía
 * el código antes— convierte una contradicción en una decisión silenciosa: el sistema
 * elegiría un importe sobre otro sin que nadie lo haya decidido, y el renglón quedaría
 * cobrado por una cifra que quizá no es la buena. Peor todavía, el resultado dependería
 * del ORDEN de las líneas dentro del archivo.
 *
 * No hay forma de saber cuál de las dos filas es la correcta: eso lo sabe quien emitió el
 * archivo, no nosotros. Así que no se aplica ninguna: se rechaza el archivo completo, sin
 * tocar un solo renglón, y se dice exactamente qué número está repetido y con qué valores.
 *
 * Un duplicado IDÉNTICO no llega acá: ese sí se puede resolver sin decidir nada —las dos
 * filas dicen lo mismo— y se cuenta como repetición informada.
 */
class ArchivoConciliacionInconsistenteException extends RuntimeException
{
    /**
     * @param  array<int, array<string, mixed>>  $filas  las filas en conflicto, tal como venían
     */
    public function __construct(
        public readonly string $numeroDocumento,
        public readonly array $filas,
    ) {
        parent::__construct(sprintf(
            'El archivo se contradice: el documento %s aparece %d veces con datos distintos (%s). '
            .'No se aplicó nada. Revisá el archivo con el cliente y volvé a subirlo.',
            $numeroDocumento,
            count($filas),
            implode(' · ', array_map(
                fn (array $f) => sprintf(
                    'línea %s: %s, %s, %s',
                    $f['linea'] ?? '?',
                    $f['tipo'] ?? '?',
                    $f['fecha'] ?? 'sin fecha',
                    $f['valor'] === null ? 'sin importe' : $f['valor'],
                ),
                $filas,
            )),
        ));
    }
}
