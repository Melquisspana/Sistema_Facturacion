<?php

namespace App\Services\Ppq\Exportadores;

use App\Exceptions\Ppq\FormatoExportacionDesconocidoException;
use App\Models\ClientePerfilDocumento;
use App\Services\Dte\Serializadores\SerializadorMhFactory;

/**
 * Devuelve el exportador que pide el perfil de un cliente, resolviendo por SLUG.
 *
 * Es la pieza que mantiene el nombre del cliente fuera del código: acá solo hay nombres
 * de FORMATOS. Mismo patrón que
 * {@see SerializadorMhFactory}.
 */
class ExportadorNcFactory
{
    /** @var array<int, class-string<ExportadorNc>> */
    private const FORMATOS = [
        ExportadorNcAlbaranV1::class,
        ExportadorNcCargaMasivaV1::class,
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

    /**
     * Nombre legible de un slug, para pantallas y listados.
     *
     * NO lanza: se usa para rotular lotes YA generados, y un lote viejo cuyo formato se
     * retiró del código sigue siendo un hecho histórico que debe poder listarse. Devolver
     * el slug crudo dice la verdad —«ese formato ya no lo conozco»— sin tumbar la pantalla
     * que muestra el historial completo. Descargarlo sí falla, y ahí sí ruidosamente
     * ({@see porSlug()}).
     */
    public static function etiqueta(string $slug): string
    {
        foreach (self::FORMATOS as $clase) {
            if ($clase::slug() === $slug) {
                return $clase::nombre();
            }
        }

        return $slug;
    }

    /**
     * Opciones para un desplegable: slug => nombre legible.
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::FORMATOS as $clase) {
            $opciones[$clase::slug()] = $clase::nombre();
        }

        return $opciones;
    }
}
