<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración clave/valor del sistema. Acceso por helpers estáticos con caché por
 * request. Los valores se guardan como texto (los booleanos como '1'/'0').
 */
class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];

    /** @var array<string, ?string> */
    private static array $cache = [];

    public static function get(string $clave, ?string $default = null): ?string
    {
        if (! array_key_exists($clave, self::$cache)) {
            self::$cache[$clave] = static::query()->where('clave', $clave)->value('valor');
        }

        return self::$cache[$clave] ?? $default;
    }

    public static function getBool(string $clave, bool $default = false): bool
    {
        $v = static::get($clave);

        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
    }

    public static function set(string $clave, string|bool|null $valor): void
    {
        $texto = is_bool($valor) ? ($valor ? '1' : '0') : $valor;
        static::updateOrCreate(['clave' => $clave], ['valor' => $texto]);
        self::$cache[$clave] = $texto;
    }

    /** Limpia la caché en memoria (útil en tests). */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }
}
