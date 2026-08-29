<?php

namespace App\Services\Ppq\Exportadores;

use App\Exceptions\Ppq\FormatoExportacionDesconocidoException;
use App\Models\ClientePerfilDocumento;

/**
 * Devuelve el exportador que pide el perfil de un cliente, resolviendo por SLUG.
 *
 * Es la pieza que mantiene el nombre del cliente fuera del código: acá solo hay nombres
 * de FORMATOS. Mismo patrón que
 * {@see \App\Services\Dte\Serializadores\SerializadorMhFactory}.
 */
class ExportadorNcFactory
{
    /** @var array<int, class-string<ExportadorNc>> */
    private const FORMATOS = [
        ExportadorNcAlbaranV1::class,
    ];

    /** @throws FormatoExportacionDesconocidoException */
    public function para(ClientePerfilDocumento $perfil): ExportadorNc
    {
        return $this->porSlug((string) $perfil->formato_export);
    }

    /** @throws FormatoExportacionDesconocidoException */
    public function porSlug(string $slug): ExportadorNc
    {
        foreach (self::FORMATOS as $clase) {
            if ($clase::slug() === $slug) {
                return app($clase);
            }
        }

        throw FormatoExportacionDesconocidoException::para($slug, self::slugs());
    }

    public function existe(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_map(fn (string $clase) => $clase::slug(), self::FORMATOS);
    }
}
