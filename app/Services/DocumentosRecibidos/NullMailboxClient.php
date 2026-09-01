<?php

namespace App\Services\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\Buzon\EstadoBuzon;
use App\Services\DocumentosRecibidos\Buzon\PaginaMensajes;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Carbon\CarbonInterface;

/**
 * Fuente de correo NO configurada. Se usa cuando el driver es 'none' o falta la
 * configuración/soporte (p. ej. la extensión IMAP). Nunca conecta a nada: el módulo
 * sigue mostrando los registros ya guardados y la revisión queda deshabilitada.
 *
 * Ya no devuelve `[]` en silencio: intentar leer sin fuente configurada es un error
 * de operación, y como tal se reporta. Quien pregunte primero por `disponible()`
 * —que es lo que hacen la pantalla y el comando— nunca llega a la excepción.
 */
class NullMailboxClient implements MailboxClient
{
    private const MOTIVO = 'El correo de compras (Yahoo/IMAP) no está configurado. '
        .'Configuralo en Configuración > Integraciones > Documentos recibidos para habilitar la revisión.';

    public function disponible(): bool
    {
        return false;
    }

    public function fuente(): string
    {
        return 'Correo no configurado (Yahoo/IMAP)';
    }

    public function estado(): EstadoBuzon
    {
        throw new BuzonInaccesibleException(self::MOTIVO);
    }

    public function mensajesDelDia(CarbonInterface $dia, int $limite, ?int $desdeUid = null): PaginaMensajes
    {
        throw new BuzonInaccesibleException(self::MOTIVO);
    }
}
