<?php

namespace App\Support\Ubicacion;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;

/**
 * Arma un trío de ubicación COHERENTE (departamento → municipio 2024 → distrito) a partir
 * de los catálogos cargados.
 *
 * Existe porque una ubicación válida no se puede componer eligiendo cada nivel por
 * separado: el distrito tiene que pertenecer al municipio, no solo al departamento (ver
 * {@see CoherenciaUbicacion}). Se elige primero el DISTRITO y de ahí se deriva su
 * municipio, que es el único orden que no puede producir un par imposible.
 *
 * La usan las factories y los datos de prueba para que representen ubicaciones reales, y
 * queda disponible para sembrar datos iniciales.
 */
final class UbicacionCoherenteFactory
{
    /**
     * Trío coherente listo para asignar a un cliente / sala / empresa / establecimiento.
     * Devuelve todo en null si los catálogos aún no están cargados.
     *
     * @return array{departamento_id: ?int, municipio_id: ?int, distrito_id: ?int}
     */
    public static function tercia(?int $departamentoId = null): array
    {
        // Se busca un distrito cuya agrupación 2024 YA tenga fila en `municipios`: así no
        // se crea ni se modifica ningún catálogo, solo se combina lo que existe.
        $distrito = Distrito::query()
            ->when($departamentoId, fn ($q) => $q->where('departamento_id', $departamentoId))
            ->whereNotNull('municipio_codigo')
            ->whereExists(fn ($q) => $q->from('municipios')
                ->whereColumn('municipios.departamento_id', 'distritos.departamento_id')
                ->whereColumn('municipios.codigo', 'distritos.municipio_codigo'))
            ->orderBy('id')
            ->first();

        if (! $distrito) {
            // Sin catálogos vinculados no se inventa nada: solo el departamento.
            return [
                'departamento_id' => $departamentoId ?? Departamento::query()->value('id'),
                'municipio_id' => null,
                'distrito_id' => null,
            ];
        }

        return [
            'departamento_id' => (int) $distrito->departamento_id,
            'municipio_id' => self::municipioDe($distrito)?->id,
            'distrito_id' => (int) $distrito->id,
        ];
    }

    /** Municipio que representa la agrupación 2024 del distrito (por departamento + código). */
    public static function municipioDe(Distrito $distrito): ?Municipio
    {
        return Municipio::query()
            ->where('departamento_id', $distrito->departamento_id)
            ->where('codigo', $distrito->municipio_codigo)
            ->orderBy('id')
            ->first();
    }
}
