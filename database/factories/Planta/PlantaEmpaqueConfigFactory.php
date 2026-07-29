<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaEmpaqueConfig>
 */
class PlantaEmpaqueConfigFactory extends Factory
{
    protected $model = PlantaEmpaqueConfig::class;

    public function definition(): array
    {
        return [
            'planta_presentacion_id' => PlantaPresentacion::factory(),
            'planta_insumo_bolsa_id' => PlantaInsumo::factory()->bolsa(),
            'planta_insumo_vinieta_id' => null,
            'marca' => null,
            'mercado' => MercadoPlanta::Nacional->value,
            'referencia_cliente' => null,
            'es_predeterminada' => false,
            'activo' => true,
            'vigente_desde' => null,
            'vigente_hasta' => null,
        ];
    }

    public function conVinieta(): static
    {
        return $this->state(fn () => [
            'planta_insumo_vinieta_id' => PlantaInsumo::factory()->vinieta(),
        ]);
    }

    public function predeterminada(): static
    {
        return $this->state(fn () => ['es_predeterminada' => true]);
    }

    public function exportacion(): static
    {
        return $this->state(fn () => ['mercado' => MercadoPlanta::Exportacion->value]);
    }
}
