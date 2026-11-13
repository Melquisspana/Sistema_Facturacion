<?php

namespace App\Support;

use App\Models\Empresa;
use App\Services\Planilla\IdentidadNegocio;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * El nombre de la empresa que se MUESTRA: pantallas, correos e impresos.
 *
 * Sale de Configuración › Empresa emisora (la misma regla de {@see IdentidadNegocio}:
 * nombre comercial y, si no hay, razón social). Nunca se escribe a mano en una vista.
 *
 * Lo usan vistas compartidas (encabezado, logo, título de la página), así que no puede
 * tumbar nada: se guarda en caché y, si no hay empresa cargada, la tabla no existe o
 * la base falla, devuelve el nombre del sistema (`config('app.name')`).
 *
 * No es un dato fiscal: el emisor del JSON de Hacienda sigue saliendo de la empresa
 * enlazada al documento.
 */
final class NombreEmpresa
{
    private const CLAVE = 'empresa.nombre_visible';

    private const SEGUNDOS = 3600;

    public static function actual(): string
    {
        // Una sola lectura de caché por pedido: el nombre sale en el logo, el título y el menú.
        $nombre = MemoDePedido::actual()->recordar(self::CLAVE, function (): string {
            try {
                return (string) Cache::remember(
                    self::CLAVE,
                    self::SEGUNDOS,
                    fn (): string => app(IdentidadNegocio::class)->negocio(),
                );
            } catch (Throwable) {
                return '';
            }
        });

        return filled($nombre) ? trim((string) $nombre) : (string) config('app.name');
    }

    /** Lo llama {@see Empresa} al guardarse: el cambio se ve en la pantalla siguiente. */
    public static function olvidar(): void
    {
        MemoDePedido::actual()->olvidar(self::CLAVE);

        try {
            Cache::forget(self::CLAVE);
        } catch (Throwable) {
            // Sin caché disponible no hay nada que olvidar.
        }
    }
}
