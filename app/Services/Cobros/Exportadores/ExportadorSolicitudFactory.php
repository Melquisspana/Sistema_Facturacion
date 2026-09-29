<?php

namespace App\Services\Cobros\Exportadores;

use App\Exceptions\Ppq\FormatoExportacionDesconocidoException;

/**
 * Devuelve el exportador de solicitudes que corresponde a un SLUG.
 *
 * Igual que la fábrica de formatos de notas de crédito, y por el mismo motivo: una
 * solicitud guarda el slug con el que se armó, así que su archivo se genera con SU formato
 * aunque el cliente ya use otro; después se entrega la copia archivada. Lo que ya se
 * presentó no se reescribe.
 */
class ExportadorSolicitudFactory
{
    /** @var array<int, class-string<ExportadorSolicitud>> */
    private const FORMATOS = [
        ExportadorSolicitudCargaMasivaV1::class,
    ];

    /** Formato con el que se arman las solicitudes NUEVAS. */
    public static function actual(): string
    {
        return ExportadorSolicitudCargaMasivaV1::slug();
    }

    /** @throws FormatoExportacionDesconocidoException */
    public function porSlug(string $slug): ExportadorSolicitud
    {
        foreach (self::FORMATOS as $clase) {
            if ($clase::slug() === $slug) {
                return app($clase);
            }
        }

        throw FormatoExportacionDesconocidoException::para($slug, self::slugs());
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_map(fn (string $clase) => $clase::slug(), self::FORMATOS);
    }

    /**
     * Nombre legible de un slug. NO lanza: se usa para rotular solicitudes YA generadas, y
     * una solicitud vieja cuyo formato se retiró del código sigue siendo un hecho que debe
     * poder listarse.
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
}
