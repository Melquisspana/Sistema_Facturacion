<?php

namespace App\Services\Ppq;

use App\Models\ClienteSucursal;
use App\Models\PpqSala;
use App\Support\Sala;

/**
 * Resuelve un código de sala de 4 dígitos (ej. 0236) a la SUCURSAL FISCAL del cliente.
 *
 * Orden de resolución (fijo):
 *  1. `cliente_sucursales.codigo` — fuente PRINCIPAL y única autoritativa: da el
 *     `cliente_sucursal_id` con el que se vincula el albarán.
 *  2. `ppq_salas` — FALLBACK y solo para el nombre. El mapa auxiliar de PPQ no es
 *     fiscal, así que resuelve cómo se llama la sala pero NUNCA da un id de sucursal.
 *  3. Ninguna de las dos — la sala queda como EXCEPCIÓN, para revisión manual.
 *
 * NUNCA crea sucursales: un código desconocido se reporta, no se inventa. Dar de alta
 * una `cliente_sucursales` es un acto fiscal y es siempre decisión de una persona.
 *
 * Cachea por instancia (los códigos se repiten mucho dentro de una sincronización).
 */
class SalaSucursalResolver
{
    /** La sala existe en `cliente_sucursales`: hay vínculo fiscal. */
    public const FUENTE_SUCURSAL = 'cliente_sucursal';

    /** Solo se conoce el nombre por el mapa auxiliar de PPQ: sin vínculo fiscal. */
    public const FUENTE_MAPA = 'ppq_sala';

    /** Código desconocido en ambas fuentes: excepción. */
    public const FUENTE_DESCONOCIDA = 'desconocida';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @return array{sala_codigo: ?string, cliente_sucursal_id: ?int, nombre: ?string, fuente: string, excepcion: bool}
     */
    public function resolver(?string $codigo): array
    {
        $codigo = Sala::normalizar($codigo);

        if ($codigo === null) {
            // Sin código no hay nada que resolver; también es una excepción (el albarán
            // no trae OC parseable ni número con el formato de Calleja).
            return $this->resultado(null, null, null, self::FUENTE_DESCONOCIDA);
        }

        if (! array_key_exists($codigo, $this->cache)) {
            $this->cache[$codigo] = $this->resolverSinCache($codigo);
        }

        return $this->cache[$codigo];
    }

    /** ¿Este código de sala queda sin resolver (excepción)? */
    public function esExcepcion(?string $codigo): bool
    {
        return $this->resolver($codigo)['excepcion'];
    }

    /** @return array{sala_codigo: ?string, cliente_sucursal_id: ?int, nombre: ?string, fuente: string, excepcion: bool} */
    private function resolverSinCache(string $codigo): array
    {
        // 1) PRINCIPAL: la sucursal fiscal por su código.
        $sucursal = $this->sucursalPorCodigo($codigo);
        if ($sucursal !== null) {
            return $this->resultado($codigo, (int) $sucursal->id, $sucursal->nombre, self::FUENTE_SUCURSAL);
        }

        // 2) FALLBACK: el mapa auxiliar de PPQ solo aporta el nombre comercial.
        $nombre = PpqSala::nombre($codigo);
        if (filled($nombre)) {
            return $this->resultado($codigo, null, $nombre, self::FUENTE_MAPA);
        }

        // 3) Desconocida: se persiste el código tal cual y se reporta como excepción.
        return $this->resultado($codigo, null, null, self::FUENTE_DESCONOCIDA);
    }

    /**
     * Sucursal por código, tolerando que esté guardado sin el cero inicial ('230' == '0230')
     * y acotando al cliente del módulo (Calleja) si está configurado, para que un mismo
     * código de otro cliente no se cuele.
     */
    private function sucursalPorCodigo(string $codigo): ?ClienteSucursal
    {
        $variantes = array_values(array_filter(array_unique([$codigo, ltrim($codigo, '0')]))) ?: [$codigo];

        $query = ClienteSucursal::query()->whereIn('codigo', $variantes);

        if ($cliente = config('ppq.cliente_default_id')) {
            $query->where('cliente_id', $cliente);
        }

        return $query->first(['id', 'nombre']);
    }

    /** @return array{sala_codigo: ?string, cliente_sucursal_id: ?int, nombre: ?string, fuente: string, excepcion: bool} */
    private function resultado(?string $codigo, ?int $sucursalId, ?string $nombre, string $fuente): array
    {
        return [
            'sala_codigo' => $codigo,
            'cliente_sucursal_id' => $sucursalId,
            'nombre' => $nombre,
            'fuente' => $fuente,
            'excepcion' => $fuente === self::FUENTE_DESCONOCIDA,
        ];
    }

    /** Limpia el caché por instancia (pruebas, o tras sembrar salas en una corrida larga). */
    public function olvidarCache(): void
    {
        $this->cache = [];
    }
}
