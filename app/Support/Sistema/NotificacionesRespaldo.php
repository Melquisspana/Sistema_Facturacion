<?php

namespace App\Support\Sistema;

/**
 * Destinatario de los avisos de respaldo (spatie/laravel-backup).
 *
 * Existe por un detalle incómodo: spatie VALIDA que `backup.notifications.mail.to`
 * sea un correo con formato válido y lanza InvalidConfig si no lo es. Dejarlo vacío
 * para señalar "sin configurar" rompería `backup:run` y `backup:clean` enteros. Por
 * eso el default es un CENTINELA con forma de correo pero en el TLD reservado
 * `.invalid` (RFC 2606): spatie lo acepta, nadie lo recibe, y el diagnóstico puede
 * reconocerlo y decir la verdad — "notificaciones de backup no configuradas" — en vez
 * del `your@example.com` de la plantilla original, que se leía como si fuera un
 * destinatario real ya puesto.
 *
 * Se configura con BACKUP_NOTIFICACIONES_CORREO en el .env del entorno.
 */
class NotificacionesRespaldo
{
    /** Centinela: formato de correo válido, dominio reservado, nadie lo recibe. */
    public const SIN_CONFIGURAR = 'respaldos-sin-configurar@invalid.local';

    /**
     * Correos que NO son un destinatario real: el centinela y el placeholder que traía
     * la plantilla de spatie (que sigue vivo en instalaciones anteriores).
     *
     * @var array<int, string>
     */
    private const PLACEHOLDERS = [
        self::SIN_CONFIGURAR,
        'your@example.com',
        'hello@example.com',
    ];

    /** Destinatarios configurados de verdad (lista ya normalizada, sin placeholders). */
    public static function destinatarios(): array
    {
        $to = config('backup.notifications.mail.to');
        $lista = is_array($to) ? $to : [$to];

        return array_values(array_filter(
            array_map(fn ($c) => strtolower(trim((string) $c)), $lista),
            fn (string $c) => $c !== '' && ! in_array($c, self::PLACEHOLDERS, true),
        ));
    }

    /** ¿Hay al menos un destinatario real para los avisos de respaldo? */
    public static function configurado(): bool
    {
        return self::destinatarios() !== [];
    }
}
