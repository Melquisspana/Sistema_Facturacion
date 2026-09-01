<?php

namespace App\Services\DocumentosRecibidos\Buzon;

/**
 * Metadatos de la carpeta abierta. Se leen ANTES de sincronizar nada.
 *
 * `uidValidity` es el número que el servidor IMAP asigna a la carpeta: mientras no
 * cambie, los UID de esa carpeta significan lo mismo. Si cambia (el buzón se
 * reconstruyó, la carpeta se borró y se recreó), TODO el progreso guardado por UID
 * deja de tener sentido. Por eso se compara con lo persistido y, si difiere, la
 * sincronización se detiene en vez de avanzar contra una referencia que ya no aplica.
 */
class EstadoBuzon
{
    public function __construct(
        public readonly string $carpeta,
        public readonly ?int $uidValidity,
        public readonly int $mensajes = 0,
    ) {}
}
