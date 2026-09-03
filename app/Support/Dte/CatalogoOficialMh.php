<?php

namespace App\Support\Dte;

use RuntimeException;

/**
 * FUENTE ÚNICA de qué Excel oficial del MH está vigente y de su integridad.
 *
 * El importador tomaba antes «el primer .xlsx del directorio» (glob). Con una sola
 * revisión en el repo eso funcionaba por accidente; al conservar la anterior —que es lo
 * que exige poder reproducir un DTE viejo— el orden alfabético habría elegido la de mayo
 * y el sistema habría seguido serializando el CAT-013 equivocado sin avisar.
 *
 * Acá la versión activa se declara en config/catalogos_mh.php y se VERIFICA por SHA-256
 * antes de usarse. Un archivo alterado a mano, truncado o reemplazado por otra revisión
 * aborta la importación en vez de entrar en la base.
 */
final class CatalogoOficialMh
{
    /** Versión activa declarada (clave del registro, p. ej. "2026-07-01"). */
    public static function version(): string
    {
        $version = config('catalogos_mh.activo');

        if (! is_string($version) || $version === '') {
            throw new RuntimeException(
                'config/catalogos_mh.php no declara una versión activa (`activo`).'
            );
        }

        return $version;
    }

    /**
     * Metadatos de una versión (por defecto, la activa).
     *
     * @return array{archivo: string, sha256: string, descripcion?: string}
     */
    public static function metadatos(?string $version = null): array
    {
        $version ??= self::version();
        $entrada = config('catalogos_mh.versiones.'.$version);

        if (! is_array($entrada) || ! isset($entrada['archivo'], $entrada['sha256'])) {
            throw new RuntimeException(
                "El catálogo oficial «{$version}» no está registrado en config/catalogos_mh.php "
                .'(o le falta `archivo`/`sha256`). Versiones registradas: '
                .implode(', ', array_keys((array) config('catalogos_mh.versiones', []))).'.'
            );
        }

        return $entrada;
    }

    /** SHA-256 esperado de la versión indicada (por defecto, la activa). */
    public static function sha256Esperado(?string $version = null): string
    {
        return self::metadatos($version)['sha256'];
    }

    /** Ruta absoluta del .xlsx de la versión indicada (por defecto, la activa). */
    public static function ruta(?string $version = null): string
    {
        return resource_path('dte/catalogos'.DIRECTORY_SEPARATOR.self::metadatos($version)['archivo']);
    }

    /**
     * Ruta del catálogo ACTIVO, con el archivo ya verificado por hash.
     *
     * @throws RuntimeException si falta el archivo o el SHA-256 no coincide
     */
    public static function rutaVerificada(?string $version = null): string
    {
        $version ??= self::version();
        $ruta = self::ruta($version);

        if (! is_file($ruta)) {
            throw new RuntimeException(
                "No se encontró el catálogo oficial «{$version}»: {$ruta}. "
                .'Colocá el .xlsx oficial en resources/dte/catalogos con ese nombre exacto.'
            );
        }

        $esperado = self::sha256Esperado($version);
        $real = hash_file('sha256', $ruta);

        if (! hash_equals($esperado, (string) $real)) {
            throw new RuntimeException(
                "El catálogo oficial «{$version}» NO coincide con su SHA-256 registrado.\n"
                ."  archivo:  {$ruta}\n"
                ."  esperado: {$esperado}\n"
                ."  real:     {$real}\n"
                .'Se aborta la importación: no se carga a la base un catálogo que no es el oficial.'
            );
        }

        return $ruta;
    }

    /** ¿El archivo de esta versión está presente y con el hash correcto? */
    public static function integro(?string $version = null): bool
    {
        try {
            self::rutaVerificada($version);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Todas las versiones registradas, de la más antigua a la más nueva.
     *
     * @return array<int, string>
     */
    public static function versiones(): array
    {
        $versiones = array_keys((array) config('catalogos_mh.versiones', []));
        sort($versiones);

        return $versiones;
    }
}
