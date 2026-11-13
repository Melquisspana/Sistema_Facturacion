<?php

namespace App\Http\Controllers;

use App\Support\NombreEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Manifiesto para instalar el sistema como app en el celular (13/11/2026): Chrome ofrece
 * «Instalar app» y el iPhone lo agrega a inicio a pantalla completa.
 *
 * Va por ruta y no como archivo para que el nombre salga de Configuración
 * ({@see NombreEmpresa}). No exige sesión: el navegador lo pide antes de entrar.
 * `start_url` es `/`, que ya manda a cada uno a su pantalla.
 */
class ManifiestoWebController extends Controller
{
    /** Fondo oscuro del sistema (ink-950 de tailwind.config.js). */
    public const COLOR = '#131417';

    /** Lo que cabe bajo el ícono en la pantalla del celular. */
    private const CORTO_MAX = 12;

    public function __invoke(): JsonResponse
    {
        $nombre = NombreEmpresa::actual();

        return response()->json([
            'name' => $nombre,
            'short_name' => self::nombreCorto($nombre),
            'lang' => 'es',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::COLOR,
            'theme_color' => self::COLOR,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }

    /** El nombre si cabe; si no, su primera palabra (recortada si hace falta). */
    public static function nombreCorto(string $nombre): string
    {
        if (mb_strlen($nombre) <= self::CORTO_MAX) {
            return $nombre;
        }

        return Str::substr(Str::before($nombre, ' '), 0, self::CORTO_MAX);
    }
}
