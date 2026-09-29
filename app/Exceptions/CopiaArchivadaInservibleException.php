<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * La COPIA ARCHIVADA de un archivo ya entregado (formato de NC o solicitud de quedan) no
 * se puede servir: falta, está alterada, el registro está incompleto o el almacenamiento
 * no se pudo leer.
 *
 * Es una falla ESPERABLE del archivo guardado, no un error de programación: la descarga
 * se detiene sin regenerar nada ni contarse, y la pantalla lo dice con un texto seguro.
 * El mensaje técnico (`getMessage()`) queda para el registro; al usuario se le muestra
 * {@see mensajeUsuario()}, sin rutas ni detalles internos.
 *
 * Extiende RuntimeException para que quien ya capturaba ese tipo lo siga haciendo.
 */
class CopiaArchivadaInservibleException extends RuntimeException
{
    public function __construct(
        string $mensajeTecnico,
        private readonly string $referencia,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensajeTecnico, 0, $anterior);
    }

    public function referencia(): string
    {
        return $this->referencia;
    }

    /** Texto para pantalla: qué pasó, qué NO se hizo y qué hacer. */
    public function mensajeUsuario(): string
    {
        return "No se pudo descargar {$this->referencia}: su copia archivada no está disponible o no coincide con "
            .'la que se guardó. No se descargó ni se volvió a generar, y la descarga no se contó. '
            .'Pedí a administración que revise la copia antes de intentarlo de nuevo.';
    }
}
