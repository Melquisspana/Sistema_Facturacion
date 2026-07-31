<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaTraslado>
 *
 * Crea BORRADORES. No hay estado `enviado()` ni `recibido()` a propósito: enviar
 * y recibir son lo que emite los movimientos, y una factory que pusiera el
 * estado a mano dejaría un traslado «en tránsito» sin saldo en tránsito —justo
 * la corrupción que la reconciliación tiene que ir a buscar—. Para tener uno
 * enviado o recibido en una prueba se llama a PlantaTrasladoService.
 */
class PlantaTrasladoFactory extends Factory
{
    protected $model = PlantaTraslado::class;

    public function definition(): array
    {
        return [
            // El número lo asigna el servicio con Secuencia; aquí uno alto y
            // único para no chocar con la serie real en pruebas de esquema.
            'numero' => $this->faker->unique()->numberBetween(900000, 999999),
            'estado' => EstadoTrasladoPlanta::Borrador->value,
            'fecha' => '2026-07-30',
            'planta_ubicacion_origen_id' => PlantaUbicacion::factory(),
            'planta_ubicacion_destino_id' => PlantaUbicacion::factory(),
            'creado_por' => null,
            'enviado_por' => null,
            'enviado_en' => null,
            'recibido_por' => null,
            'recibido_en' => null,
            'responsable_user_id' => null,
            'responsable_nombre' => null,
            'observaciones' => null,
            'motivo_reversion' => null,
            'reversion_de_id' => null,
            'revertido_por_id' => null,
        ];
    }

    public function cancelado(): static
    {
        return $this->state(fn () => ['estado' => EstadoTrasladoPlanta::Cancelado->value]);
    }
}
