<?php

namespace App\Services\DocumentosRecibidos\Buzon;

/**
 * Identidad ESTABLE de un correo del buzón de compras.
 *
 * POR QUÉ EXISTE. Hasta ahora la identidad era el UID de IMAP guardado en
 * `gmail_message_id`. Un UID solo es único dentro de UNA carpeta y mientras el
 * `UIDVALIDITY` de esa carpeta no cambie: mover un correo de INBOX a un archivo lo
 * vuelve "nuevo" (duplicado), y una reconstrucción del buzón hace que el UID 1803
 * apunte a otro correo (falso duplicado, el peor de los dos). Con solape y reintentos
 * —que es justamente lo que hace falta para no perder correos— esa clave no aguanta.
 *
 * QUÉ SE USA AHORA, en este orden:
 *
 *  1. `Message-ID` del encabezado (RFC 5322), normalizado. Lo pone el servidor que
 *     ORIGINA el correo, viaja con él y no cambia al moverlo de carpeta ni al
 *     reconstruir el buzón. Es la identidad correcta.
 *  2. Si el correo no trae `Message-ID` —pasa con algunos generadores de facturas—,
 *     un hash DETERMINISTA de lo que sí lo identifica y tampoco depende de la
 *     carpeta: fecha, remitente, asunto y nombres de adjuntos. El mismo correo leído
 *     dos veces, desde donde sea, produce el mismo hash.
 *
 * El prefijo (`mid:` / `hash:`) queda a la vista a propósito: al mirar la fila se sabe
 * si la identidad es la del correo o una reconstruida, sin adivinar por el formato.
 */
class IdentidadCorreo
{
    public const PREFIJO_MESSAGE_ID = 'mid:';

    public const PREFIJO_HASH = 'hash:';

    /** Identidad de las filas anteriores a esta migración: el UID crudo de entonces. */
    public const PREFIJO_LEGADO = 'legado:';

    /**
     * Identidad y `message_id` normalizado de un mensaje ya leído del buzón.
     *
     * @param  array<string, mixed>  $mensaje
     * @return array{identidad: string, message_id: ?string}
     */
    public static function para(array $mensaje): array
    {
        $messageId = self::normalizar($mensaje['message_id'] ?? null);

        if ($messageId !== null) {
            return ['identidad' => self::PREFIJO_MESSAGE_ID.$messageId, 'message_id' => $messageId];
        }

        return ['identidad' => self::PREFIJO_HASH.self::huella($mensaje), 'message_id' => null];
    }

    /**
     * `Message-ID` en forma canónica: sin `<>`, sin espacios, en minúsculas.
     *
     * La parte local de un Message-ID es sensible a mayúsculas según el RFC, pero en
     * la práctica ningún servidor cambia la caja de un encabezado que reenvía, y
     * normalizarla evita que el MISMO correo entre dos veces si un intermediario lo
     * hiciera. Preferimos el falso duplicado (que se descarta) al doble registro.
     */
    public static function normalizar(mixed $bruto): ?string
    {
        if (! is_string($bruto)) {
            return null;
        }

        $limpio = trim($bruto);
        $limpio = trim($limpio, '<>');
        $limpio = (string) preg_replace('/\s+/u', '', $limpio);

        // Un Message-ID sin arroba no identifica nada (no tiene dominio de origen):
        // se trata como ausente y se cae al hash, que sí es determinista.
        if ($limpio === '' || ! str_contains($limpio, '@')) {
            return null;
        }

        return mb_strtolower(mb_substr($limpio, 0, 180));
    }

    /**
     * Huella determinista para correos SIN `Message-ID`.
     *
     * Solo entran datos que viajan con el correo: fecha, remitente, asunto y nombres
     * de adjuntos ordenados. NO entran el UID ni la carpeta, que es precisamente lo
     * que se quería dejar de usar. Dos lecturas del mismo correo —hoy en INBOX, mañana
     * en otra carpeta— dan el mismo hash.
     *
     * @param  array<string, mixed>  $mensaje
     */
    private static function huella(array $mensaje): string
    {
        $adjuntos = array_map(
            fn ($a) => mb_strtolower(trim((string) ($a['filename'] ?? ''))),
            (array) ($mensaje['adjuntos'] ?? []),
        );
        sort($adjuntos, SORT_STRING);

        $partes = [
            self::texto($mensaje['fecha'] ?? null),
            self::texto($mensaje['remitente'] ?? null),
            self::texto($mensaje['asunto'] ?? null),
            implode(',', $adjuntos),
        ];

        return sha1(implode("\n", $partes));
    }

    private static function texto(mixed $v): string
    {
        return mb_strtolower(trim((string) (is_scalar($v) ? $v : '')));
    }
}
