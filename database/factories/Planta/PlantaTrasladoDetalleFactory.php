<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaTrasladoDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaTrasladoDetalle>
 */
class PlantaTrasladoDetalleFactory extends Factory
{
    protected $model = PlantaTrasladoDetalle::class;

    public function definition(): array
    {
        $insumo = PlantaInsumo::factory();

        return [
            'planta_traslado_id' => PlantaTraslado::factory(),
            'planta_insumo_id' => $insumo,
            'planta_lote_id' => PlantaLote::factory(),
            'cantidad' => '100.0000',
            'unidad_base' => UnidadBase::Libra->value,
            'observaciones' => null,
        ];
    }
}
