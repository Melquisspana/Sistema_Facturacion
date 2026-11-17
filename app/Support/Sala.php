<?php

namespace App\Support;

use App\Facades\Ajustes;
use App\Models\ClienteSucursal;
use App\Models\PpqSala;

/**
 * Resuelve el nombre de una sala/CD a partir de su código de 4 dígitos (ej. 0260),
 * buscándolo en `cliente_sucursales.codigo`. Si no existe, devuelve solo el código.
 *
 * El match tolera códigos guardados sin el cero inicial (230 == 0230) y, si está
 * configurado `ppq.cliente_default_id` (Ajustes: pantalla o .env), acota la búsqueda a ese cliente (Calleja)
 * para evitar choques de código entre clientes. Cachea por request.
 *
 * Una lista que muestra muchas salas llama antes a {@see precargar()} con todos sus códigos:
 * así son dos consultas para toda la página y no una o dos por sala.
 */
class Sala
{
    /** @var array<string, ?string> */
    private static array $cache = [];

    /** Nombre de la sala para un código de 4 dígitos, o null si no está en la BD. */
    public static function nombre(?string $codigo): ?string
    {
        $codigo = self::normalizar($codigo);
        if ($codigo === null) {
            return null;
        }

        self::precargar([$codigo]);

        return self::$cache[$codigo];
    }

    /**
     * Busca de una vez el nombre de todos estos códigos y lo deja guardado para {@see nombre()}:
     * una consulta a `cliente_sucursales` y, si alguno no resuelve, otra al mapa de PPQ, para
     * toda la lista. Los códigos que ya están guardados no se vuelven a buscar.
     *
     * @param  iterable<?string>  $codigos
     */
    public static function precargar(iterable $codigos): void
    {
        $faltan = [];
        foreach ($codigos as $codigo) {
            $codigo = self::normalizar($codigo);
            if ($codigo !== null && ! array_key_exists($codigo, self::$cache)) {
                // Tolera el código guardado con o sin cero inicial ('0230' o '230'), de
                // forma portable (sin LPAD, que SQLite no tiene).
                $faltan[$codigo] = array_values(array_filter(array_unique([$codigo, ltrim($codigo, '0')]))) ?: [$codigo];
            }
        }
        if ($faltan === []) {
            return;
        }

        $query = ClienteSucursal::query()->whereIn('codigo', array_values(array_unique(array_merge(...array_values($faltan)))));

        if ($cliente = Ajustes::cliente('ppq.cliente_default_id')) {
            $query->where('cliente_id', $cliente);
        }

        // Si dos sucursales tienen el mismo código, la de id menor: la primera que devolvía la
        // búsqueda de a un código, que no pedía orden.
        $sucursales = $query->orderBy('id')->get(['codigo', 'nombre']);

        // Fiscal primero (cliente_sucursales por código); si no resuelve —lo normal en
        // Calleja, cuyas sucursales tienen `codigo` nulo—, se cae al mapa auxiliar de PPQ
        // (nombres que ya vimos en JSON de CCF o altas manuales). El mapa NO es fiscal.
        $nombres = [];
        foreach ($faltan as $codigo => $variantes) {
            $nombres[$codigo] = $sucursales->first(fn (ClienteSucursal $s) => in_array((string) $s->codigo, $variantes, true))?->nombre;
        }
        PpqSala::precargar(array_keys(array_filter($nombres, fn (?string $nombre) => $nombre === null)));

        foreach ($nombres as $codigo => $nombre) {
            self::$cache[$codigo] = $nombre ?? PpqSala::nombre($codigo);
        }
    }

    /** Etiqueta "0260 - Nombre" si hay nombre; si no, solo "0260". Null si no hay código. */
    public static function etiqueta(?string $codigo): ?string
    {
        $codigo = self::normalizar($codigo);
        if ($codigo === null) {
            return null;
        }

        $nombre = self::nombre($codigo);

        return $nombre ? $codigo.' - '.$nombre : $codigo;
    }

    /**
     * Mejor nombre comercial para mostrar de una sala. PREFIERE el nombre de la sucursal
     * relacionada al documento (cuando el CCF trae `cliente_sucursal_id`), porque es el dato
     * autoritativo; si no lo hay, lo busca por el código de 4 dígitos en `cliente_sucursales`.
     * Devuelve null cuando ninguno resuelve (la vista decide el texto de respaldo).
     */
    public static function nombrePreferido(?string $codigo, ?string $nombreSucursal = null): ?string
    {
        return filled($nombreSucursal) ? $nombreSucursal : self::nombre($codigo);
    }

    /**
     * Descripción lista para pantalla: "Súper Selectos La Sultana" o, si no hay nombre,
     * "Sala 0023 sin nombre registrado". Nunca queda vacío.
     */
    public static function descripcion(?string $codigo, ?string $nombreSucursal = null): string
    {
        $nombre = self::nombrePreferido($codigo, $nombreSucursal);
        if (filled($nombre)) {
            return $nombre;
        }

        $cod = self::normalizar($codigo);

        return $cod ? "Sala {$cod} sin nombre registrado" : 'Sala sin código';
    }

    /** Limpia el caché por-request (útil en pruebas, donde la BD se refresca entre tests). */
    public static function olvidarCache(): void
    {
        self::$cache = [];
        PpqSala::olvidarCache();
    }

    /** Normaliza un código a 4 dígitos con cero inicial; null si no es de 1-4 dígitos. */
    public static function normalizar(?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);

        return preg_match('/^\d{1,4}$/', $codigo) ? str_pad($codigo, 4, '0', STR_PAD_LEFT) : ($codigo === '' ? null : $codigo);
    }
}
