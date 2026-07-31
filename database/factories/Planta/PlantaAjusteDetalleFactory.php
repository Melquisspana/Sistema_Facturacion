<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaAjusteDetalle;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaAjusteDetalle>
 */
class PlantaAjusteDetalleFactory extends Factory
{
    protected $model = PlantaAjusteDetalle::class;

    public function definition(): array
    {
        return [
            'planta_ajuste_id' => PlantaAjuste::factory(),
            'planta_insumo_id' => PlantaInsumo::factory(),
            'planta_lote_id' => PlantaLote::factory(),
            'planta_ubicacion_id' => PlantaUbicacion::factory(),
            'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
            'cantidad' => '50.0000',
            'cantidad_conteo' => null,
            'cantidad_sistema' => null,
            'diferencia' => null,
            'unidad_base' => UnidadBase::Libra->value,
            'observaciones' => null,
        ];
    }

    /** Línea de corrección de conteo: la cantidad se deriva de la diferencia. */
    public function conteo(string $contado = '80'): static
    {
        return $this->state(fn () => [
            'cantidad' => '0.0000',
            'cantidad_conteo' => $contado,
        ]);
    }
}
