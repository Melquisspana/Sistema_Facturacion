<?php

namespace App\Services\DocumentosRecibidos;

use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Throwable;

/**
 * Lector IMAP de SOLO LECTURA para el buzón de documentos recibidos (Yahoo).
 *
 * Garantías:
 *  - Abre el buzón en modo OP_READONLY.
 *  - Lee cuerpos con FT_PEEK: NO marca los correos como leídos.
 *  - NUNCA borra (imap_delete), NUNCA mueve (imap_mail_move) ni cambia flags.
 *  - Si falta la extensión imap o la configuración, `disponible()` = false y no
 *    intenta conectar (el módulo muestra "Configurar correo Yahoo/IMAP").
 *
 * Las credenciales vienen SOLO de config (env), nunca del repo, y no se registran.
 */
class ImapMailboxClient implements MailboxClient
{
    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = (array) config('documentos_recibidos.mail', []);
    }

    public function disponible(): bool
    {
        return function_exists('imap_open')
            && strtolower((string) ($this->cfg['driver'] ?? '')) === 'imap'
            && filled($this->cfg['host'] ?? null)
            && filled($this->cfg['username'] ?? null)
            && filled($this->cfg['password'] ?? null);
    }

    public function fuente(): string
    {
        if (! function_exists('imap_open')) {
            return 'IMAP no soportado por el servidor (falta la extensión imap de PHP)';
        }
        $host = (string) ($this->cfg['host'] ?? '');
        $user = (string) ($this->cfg['username'] ?? '');

        // El usuario es un correo (no secreto); la contraseña NUNCA se muestra.
        return $this->disponible()
            ? 'IMAP '.$user.($host !== '' ? ' ('.$host.')' : '')
            : 'Correo Yahoo/IMAP sin configurar';
    }

    public function mensajesConAdjuntos(int $limite = 30): array
    {
        if (! $this->disponible()) {
            return [];
        }

        $conn = $this->abrir();
        if ($conn === null) {
            return [];
        }

        try {
            $criterio = (string) ($this->cfg['search'] ?? 'ALL');
            $ids = @imap_search($conn, $criterio !== '' ? $criterio : 'ALL', SE_UID) ?: [];
            // Más recientes primero, acotado al límite.
            rsort($ids);
            $ids = array_slice($ids, 0, max(1, $limite));

            $mensajes = [];
            foreach ($ids as $uid) {
                $mensaje = $this->leerMensaje($conn, (int) $uid);
                if ($mensaje !== null && $mensaje['adjuntos'] !== []) {
                    $mensajes[] = $mensaje;
                }
            }

            return $mensajes;
        } catch (Throwable) {
            return [];
        } finally {
            // Cierra sin expunge (no borra nada).
            @imap_close($conn);
        }
    }

    /** Abre la conexión IMAP en SOLO LECTURA. Devuelve null si no se pudo. */
    private function abrir()
    {
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?? 993);
        $enc = strtolower((string) ($this->cfg['encryption'] ?? 'ssl'));
        $folder = (string) ($this->cfg['folder'] ?? 'INBOX');
        $flags = '/imap'.($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '')).'/readonly';
        $mailbox = '{'.$host.':'.$port.$flags.'}'.$folder;

        if (is_int($this->cfg['timeout'] ?? null)) {
            @imap_timeout(IMAP_OPENTIMEOUT, (int) $this->cfg['timeout']);
        }

        try {
            // OP_READONLY: no marca leído; no permite escritura destructiva.
            $conn = @imap_open($mailbox, (string) $this->cfg['username'], (string) $this->cfg['password'], OP_READONLY);

            return $conn ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lee un mensaje por UID y devuelve sus adjuntos PDF/JSON/XML (con FT_PEEK, sin
     * marcar leído). @return array<string, mixed>|null
     */
    private function leerMensaje($conn, int $uid): ?array
    {
        $overview = @imap_fetch_overview($conn, (string) $uid, FT_UID);
        $info = $overview[0] ?? null;
        $estructura = @imap_fetchstructure($conn, $uid, FT_UID);
        if (! $estructura) {
            return null;
        }

        $adjuntos = [];
        $this->recorrerPartes($estructura, function ($parte, string $seccion) use (&$adjuntos, $conn, $uid) {
            $nombre = $this->nombreAdjunto($parte);
            if ($nombre === null) {
                return;
            }
            $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'json', 'xml'], true)) {
                return;
            }
            // FT_PEEK: leer SIN marcar el correo como leído.
            $raw = @imap_fetchbody($conn, $uid, $seccion, FT_UID | FT_PEEK);
            $data = $this->decodificarParte((string) $raw, (int) ($parte->encoding ?? 0));
            $adjuntos[] = [
                'filename' => $nombre,
                'mime' => $this->mime($parte),
                'data' => $data,
            ];
        });

        return [
            'id' => (string) $uid,
            'asunto' => isset($info->subject) ? $this->decodeMime((string) $info->subject) : null,
            'remitente' => isset($info->from) ? $this->decodeMime((string) $info->from) : null,
            'fecha' => isset($info->date) ? (string) $info->date : null,
            'adjuntos' => $adjuntos,
        ];
    }

    private function recorrerPartes(object $estructura, callable $cb, string $prefijo = ''): void
    {
        if (! isset($estructura->parts) || ! is_array($estructura->parts)) {
            // Mensaje de una sola parte.
            if ($prefijo === '') {
                $cb($estructura, '1');
            }

            return;
        }
        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijo === '' ? (string) ($i + 1) : $prefijo.'.'.($i + 1);
            $cb($parte, $seccion);
            if (isset($parte->parts) && is_array($parte->parts)) {
                $this->recorrerPartes($parte, $cb, $seccion);
            }
        }
    }

    private function nombreAdjunto(object $parte): ?string
    {
        foreach (['dparameters', 'parameters'] as $grupo) {
            foreach ((array) ($parte->$grupo ?? []) as $p) {
                $attr = strtolower((string) ($p->attribute ?? ''));
                if (in_array($attr, ['filename', 'name'], true) && filled($p->value ?? null)) {
                    return $this->decodeMime((string) $p->value);
                }
            }
        }

        return null;
    }

    private function mime(object $parte): string
    {
        $tipos = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $tipo = $tipos[$parte->type ?? 7] ?? 'application';
        $sub = strtolower((string) ($parte->subtype ?? 'octet-stream'));

        return $tipo.'/'.$sub;
    }

    private function decodificarParte(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw),      // BASE64
            4 => (string) quoted_printable_decode($raw), // QUOTED-PRINTABLE
            default => $raw,
        };
    }

    private function decodeMime(string $s): string
    {
        $out = '';
        foreach ((array) imap_mime_header_decode($s) as $part) {
            $out .= (string) $part->text;
        }

        return $out !== '' ? $out : $s;
    }
}
