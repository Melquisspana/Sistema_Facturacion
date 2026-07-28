<?php

namespace Database\Factories\Planta;

use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaLote>
 */
class PlantaLoteFactory extends Factory
{
    protected $model = PlantaLote::class;

    public function definition(): array
    {
        return [
            'planta_insumo_id' => PlantaInsumo::factory(),
            'planta_proveedor_id' => null,
            'codigo_interno' => 'INT-'.$this->faker->unique()->numerify('20260728-####'),
            'codigo_proveedor' => null,
            'es_generico' => false,
            // NOT NULL siempre: es la fecha operativa del documento que lo creó.
            'fecha_recepcion' => '2026-07-28',
            'fecha_elaboracion' => null,
            'fecha_vencimiento' => null,
            'activo' => true,
        ];
    }

    /** Lote genérico de un insumo que no controla lotes: código determinista. */
    public function generico(PlantaInsumo $insumo): static
    {
        return $this->state(fn () => [
            'planta_insumo_id' => $insumo->id,
            'codigo_interno' => 'GEN-'.$insumo->id,
            'es_generico' => true,
        ]);
    }
}
