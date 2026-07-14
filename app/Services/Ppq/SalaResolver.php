<?php

namespace App\Services\Ppq;

use App\Models\Dte;

/**
 * Resuelve el NOMBRE COMERCIAL de la sala de un CCF/NC de Calleja = el `nombre` de la
 * sucursal (ClienteSucursal) relacionada al documento. Como los prontos pagos son SOLO de
 * Calleja y todos sus CCF traen `cliente_sucursal_id`, este es el dato autoritativo.
 *
 * El documento puede venir de Gmail (sin DTE local cargado), así que se busca el DTE local
 * por cualquiera de sus identificadores —código de generación, número de control u orden de
 * compra (la OC es la llave más confiable: siempre está y define la sala)— y se toma el
 * nombre de su sucursal. Cachea por request.
 */
class SalaResolver
{
    /** @var array<string, ?string> */
    private array $cache = [];

    public function nombre(?string $oc, ?string $codigoGeneracion = null, ?string $numeroControl = null): ?string
    {
        $clave = implode('|', [$oc, $codigoGeneracion, $numeroControl]);
        if (array_key_exists($clave, $this->cache)) {
            return $this->cache[$clave];
        }

        // Se prueban las llaves de la MÁS precisa a la menos: código de generación y número
        // de control identifican el documento exacto; la OC identifica la sala (puede repetirse
        // entre CCF de la misma sucursal, pero todos comparten la misma sala → mismo nombre).
        foreach ([['codigo_generacion', $codigoGeneracion], ['numero_control', $numeroControl], ['numero_orden_compra', $oc]] as [$columna, $valor]) {
            if (blank($valor)) {
                continue;
            }
            $nombre = Dte::query()
                ->whereNotNull('cliente_sucursal_id')
                ->where($columna, $valor)
                ->latest('id')
                ->with('clienteSucursal:id,nombre')
                ->first(['id', 'cliente_sucursal_id'])
                ?->clienteSucursal?->nombre;

            if (filled($nombre)) {
                return $this->cache[$clave] = $nombre;
            }
        }

        // Sin DTE local (documento de otro sistema): último recurso, el mapa auxiliar de
        // PPQ por código de sala (derivado de la OC). Nombres ya vistos en JSON/altas.
        $mapa = \App\Support\Sala::nombre(\App\Support\OrdenCompra::salaDesde($oc));
        if (filled($mapa)) {
            return $this->cache[$clave] = $mapa;
        }

        return $this->cache[$clave] = null;
    }
}
