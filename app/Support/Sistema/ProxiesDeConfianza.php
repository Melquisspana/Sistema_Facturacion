<?php

namespace App\Support\Sistema;

/**
 * Lista de proxies cuyas cabeceras X-Forwarded-* se creen (`trustedproxy.proxies`).
 *
 * Solo lo que llega desde uno de estos orígenes puede decir cuál es la IP real del
 * visitante y si entró por HTTPS. Si la lista fuera demasiado amplia, cualquiera que
 * llegue desde ahí podría inventar su IP (y saltarse el límite de intentos de login o
 * el de los lectores) o hacerse pasar por HTTPS.
 *
 * En Windows el único proxy es Tailscale Serve, en 127.0.0.1: es el valor por defecto
 * y el comportamiento de siempre. En Docker se configura con TRUSTED_PROXIES la IP fija
 * del contenedor de cloudflared (ver docs/MIGRACION_LINUX_DOCKER.md §3.2).
 *
 * Un valor con comodines, que abarque todas las direcciones o mal escrito NO se acepta
 * a medias: se vuelve entero al valor por defecto, que es el más cerrado.
 */
class ProxiesDeConfianza
{
    public const POR_DEFECTO = ['127.0.0.1'];

    /** @return list<string> */
    public static function desde(mixed $valor): array
    {
        if (! is_string($valor) || trim($valor) === '') {
            return self::POR_DEFECTO;
        }

        $entradas = array_values(array_filter(array_map('trim', explode(',', $valor)), fn (string $e) => $e !== ''));

        foreach ($entradas as $entrada) {
            if (! self::esDireccionORed($entrada)) {
                return self::POR_DEFECTO;
            }
        }

        return $entradas === [] ? self::POR_DEFECTO : $entradas;
    }

    /**
     * Una IP (v4 o v6) o una red CIDR con prefijo distinto de cero. Quedan fuera '*',
     * '**' y 'REMOTE_ADDR' (que Laravel lee como «confiar en quien llame») y cualquier
     * red /0 (0.0.0.0/0, ::/0), que es lo mismo que confiar en todos.
     */
    private static function esDireccionORed(string $entrada): bool
    {
        $partes = explode('/', $entrada);

        if (count($partes) > 2) {
            return false;
        }

        $ip = $partes[0];
        $esV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $esV6 = ! $esV4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $esV4 && ! $esV6) {
            return false;
        }

        if (count($partes) === 1) {
            return true;
        }

        $prefijo = $partes[1];
        $maximo = $esV4 ? 32 : 128;

        return ctype_digit($prefijo) && (int) $prefijo >= 1 && (int) $prefijo <= $maximo;
    }
}
