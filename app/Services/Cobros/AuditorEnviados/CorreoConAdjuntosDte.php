<?php

namespace App\Services\Cobros\AuditorEnviados;

/**
 * Un correo del barrido con los DTE que se lograron leer de sus adjuntos JSON.
 *
 * Agrupar por `messageId` (en vez de una lista plana de adjuntos) es lo que permite
 * distinguir sin ambigüedad un correo con VARIOS adjuntos legítimos (mismo id, varias
 * entradas en `adjuntos`) de un correo que el barrido devolvió DOS VECES (mismo id
 * repetido como objeto distinto en la lista que recibe el analizador): lo primero se
 * procesa entero: lo segundo se cuenta como duplicado y se descarta.
 */
final class CorreoConAdjuntosDte
{
    /** @param  array<int, AdjuntoDteLeido>  $adjuntos  vacío si ningún adjunto se pudo leer como JSON de un DTE */
    public function __construct(
        public readonly string $messageId,
        public readonly array $adjuntos = [],
    ) {}
}
