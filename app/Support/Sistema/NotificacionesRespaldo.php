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
     * Valor para `backup.notifications.mail.to`, con la CADENA VACÍA normalizada a
     * ausencia.
     *
     * `env('BACKUP_NOTIFICACIONES_CORREO', ...)` no alcanzaba: el default de `env()`
     * solo entra cuando la clave NO EXISTE, y `.env.example` la trae declarada y
     * vacía —que es la forma correcta de decir «esto se rellena en cada servidor»—.
     * Con eso, spatie recibía `''`, lo validaba como correo y lanzaba
     * `InvalidConfig: is not a valid email address` al construir su configuración.
     * Eso ocurre en el arranque, así que tumbaba `package:discover`, `artisan` entero
     * y con ello cualquier instalación hecha copiando la plantilla.
     *
     * Declarada-y-vacía y ausente pasan a significar lo mismo: sin configurar. El
     * comportamiento con la variable ausente y con un correo real no cambia.
     */
    public static function destinatarioConfigurado(mixed $valor): string
    {
        $correo = trim((string) (is_array($valor) ? '' : $valor));

        return $correo !== '' ? $correo : self::SIN_CONFIGURAR;
    }

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

    /**
     * Canales por los que spatie avisa de un respaldo: los de siempre, o NINGUNO.
     *
     * El centinela existe para que spatie ARRANQUE —valida que `to` tenga forma de
     * correo y sin eso lanza `InvalidConfig`—, pero no es un destinatario: es una
     * dirección en un TLD reservado que nadie recibe. Mientras el correo del sistema
     * fue `log` eso no se notaba; con `MAIL_MAILER=smtp` en producción, dejar el canal
     * abierto significa un intento de entrega real a un dominio inexistente cada noche,
     * en cada respaldo y en cada limpieza. Un rebote diario que nadie lee, y —peor— un
     * `backup:run` que puede acabar reportando fallo por no haber podido avisar de que
     * fue bien.
     *
     * Así que cuando no hay destinatario real no se manda a ningún sitio: se apaga el
     * canal. `via()` de spatie hace `array_filter` sobre esta lista, y con la lista
     * vacía la notificación no se envía por ningún medio. El respaldo se hace igual, y
     * el panel de Salud del sistema sigue diciendo que los avisos no están
     * configurados — que es la información que de verdad hace falta.
     *
     * @return list<string>
     */
    public static function canalesDeAviso(mixed $valor): array
    {
        return self::destinatarioConfigurado($valor) === self::SIN_CONFIGURAR ? [] : ['mail'];
    }

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
