<?php

namespace App\Support\Ubicacion;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use Illuminate\Support\Collection;

/**
 * FUENTE ÚNICA de las opciones de la cascada Departamento → Municipio 2024 → Distrito
 * que alimentan el componente `<x-ubicacion-selects>` (empresa, establecimientos,
 * clientes y salas de clientes).
 *
 * Resuelve dos trampas de los datos:
 *  1. `municipios.nombre` quedó con el nombre PRE-2024 ("Ilobasco") mientras `codigo` ya
 *     es el de la agrupación nueva (11 = Cabañas Oeste) → se muestra `nombreFiscal()`.
 *  2. Varias filas de un departamento comparten el mismo código CAT-013 (son la misma
 *     agrupación) → se ofrece UNA sola opción por (departamento, código).
 *
 * Cada distrito viaja con `municipio_codigo`, que es lo que permite filtrar el tercer
 * select por el municipio elegido en lugar de por todo el departamento.
 */
final class OpcionesUbicacion
{
    /** @return Collection<int, Departamento> */
    public static function departamentos(): Collection
    {
        return Departamento::where('activo', true)->orderBy('nombre')->get();
    }

    /**
     * Municipios fiscales 2024, uno por agrupación, con su nombre oficial.
     *
     * Se devuelven objetos Municipio reales (los formularios guardan `municipio_id`), con
     * `nombre_fiscal` agregado para mostrar. Se conserva `nombre` intacto por si alguna
     * vista o el importador lo necesitan.
     *
     * @return Collection<int, Municipio>
     */
    public static function municipios(): Collection
    {
        return Municipio::fiscalesUnicos()
            ->filter(fn (Municipio $m) => $m->activo !== false)
            ->each(fn (Municipio $m) => $m->setAttribute('nombre_fiscal', $m->nombreFiscal()))
            ->sortBy(fn (Municipio $m) => $m->getAttribute('nombre_fiscal'))
            ->values();
    }

    /**
     * Distritos activos con el código del municipio al que pertenecen.
     *
     * @return Collection<int, Distrito>
     */
    public static function distritos(): Collection
    {
        return Distrito::where('activo', true)
            ->orderBy('municipio')->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo', 'municipio', 'municipio_codigo', 'departamento_id']);
    }

    /**
     * Las tres colecciones listas para pasar a la vista.
     *
     * @return array{departamentos: Collection<int, Departamento>, municipios: Collection<int, Municipio>, distritos: Collection<int, Distrito>}
     */
    public static function todas(): array
    {
        return [
            'departamentos' => self::departamentos(),
            'municipios' => self::municipios(),
            'distritos' => self::distritos(),
        ];
    }
}
