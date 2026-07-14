<?php

namespace App\Models;

use App\Support\Sala;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapa auxiliar de PPQ: código de sala (4 dígitos) -> nombre comercial detectado.
 *
 * Caché SOLO de PPQ para mostrar el nombre de la sala cuando el documento viene de
 * otro sistema (ContaPortable) y no hay DTE local ni código en `cliente_sucursales`.
 * NO es fiscal: no crea sucursales, no se usa para emitir.
 */
class PpqSala extends Model
{
    protected $table = 'ppq_salas';

    protected $fillable = ['codigo', 'nombre', 'fuente'];

    /** @var array<string, ?string> caché por request */
    private static array $cache = [];

    /** Nombre comercial cacheado para un código de sala, o null si no está. */
    public static function nombre(?string $codigo): ?string
    {
        $codigo = Sala::normalizar($codigo);
        if ($codigo === null) {
            return null;
        }

        if (! array_key_exists($codigo, self::$cache)) {
            self::$cache[$codigo] = static::query()->where('codigo', $codigo)->value('nombre');
        }

        return self::$cache[$codigo];
    }

    /**
     * Guarda/actualiza el nombre de una sala en el mapa. No pisa un nombre existente
     * con uno vacío. `manual` tiene prioridad: no lo sobrescribe una fuente automática.
     */
    public static function recordar(?string $codigo, ?string $nombre, string $fuente = 'ppq'): void
    {
        $codigo = Sala::normalizar($codigo);
        if ($codigo === null || blank($nombre)) {
            return;
        }

        $existente = static::query()->where('codigo', $codigo)->first();
        if ($existente && $existente->fuente === 'manual' && $fuente !== 'manual') {
            return; // el nombre confirmado a mano manda
        }

        static::query()->updateOrCreate(
            ['codigo' => $codigo],
            ['nombre' => trim($nombre), 'fuente' => $fuente],
        );
        unset(self::$cache[$codigo]);
    }

    /** Limpia el caché por-request (pruebas). */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }
}
