<?php

namespace App\Services\DocumentosRecibidos;

use App\Services\DocumentosRecibidos\Contracts\MailboxClient;

/**
 * Fuente de correo NO configurada. Se usa cuando el driver es 'none' o falta la
 * configuración/soporte (p. ej. la extensión IMAP). Nunca conecta a nada: el módulo
 * sigue mostrando los registros ya guardados y la revisión queda deshabilitada.
 */
class NullMailboxClient implements MailboxClient
{
    public function disponible(): bool
    {
        return false;
    }

    public function fuente(): string
    {
        return 'Correo no configurado (Yahoo/IMAP)';
    }

    public function mensajesConAdjuntos(int $limite = 30): array
    {
        return [];
    }
}
