<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaRecepcionDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaRecepcionDetalle>
 *
 * La `cantidad_base` por defecto es COHERENTE con la fórmula (5 × 100 × 1 = 500)
 * para que una línea de factory no nazca ya descuadrada. Las pruebas que
 * necesitan una incoherencia la fijan explícitamente, que es como debe verse.
 */
class PlantaRecepcionDetalleFactory extends Factory
{
    protected $model = PlantaRecepcionDetalle::class;

    public function definition(): array
    {
        return [
            'planta_recepcion_id' => PlantaRecepcion::factory(),
            'planta_insumo_id' => PlantaInsumo::factory(),
            'planta_lote_id' => null,
            'cantidad_recibida' => '5.0000',
            'unidad_recibida' => 'saco',
            'contenido_por_unidad' => '100.0000',
            'factor_conversion' => '1.00000000',
            'cantidad_base' => '500.0000',
            'unidad_base' => UnidadBase::Libra->value,
            'estado_destino' => EstadoDisponibilidad::Disponible->value,
            'lote_codigo_proveedor' => null,
            'fecha_elaboracion' => null,
            'fecha_vencimiento' => null,
            'observaciones' => null,
        ];
    }

    /** Línea que entra retenida a la espera de revisión de calidad. */
    public function retenida(): static
    {
        return $this->state(fn () => ['estado_destino' => EstadoDisponibilidad::Retenido->value]);
    }

    /** Línea de un insumo contado por unidades enteras (bolsas, viñetas). */
    public function porUnidad(): static
    {
        return $this->state(fn () => [
            'unidad_recibida' => 'paquete',
            'contenido_por_unidad' => '100.0000',
            'cantidad_recibida' => '20.0000',
            'cantidad_base' => '2000.0000',
            'unidad_base' => UnidadBase::Unidad->value,
        ]);
    }
}
