<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaRecepcion>
 *
 * Crea BORRADORES. No existe un estado `confirmada()` a propósito: confirmar es
 * lo que mueve inventario, y una factory que pusiera el estado a mano dejaría
 * documentos confirmados sin movimientos ni saldo, es decir, exactamente la
 * corrupción que la reconciliación tiene que ir a buscar. Para tener una
 * recepción confirmada en una prueba se llama a PlantaRecepcionService.
 */
class PlantaRecepcionFactory extends Factory
{
    protected $model = PlantaRecepcion::class;

    public function definition(): array
    {
        return [
            // El número lo asigna el servicio con Secuencia; aquí se genera uno
            // alto y único para no chocar con la serie real en pruebas de esquema.
            'numero' => $this->faker->unique()->numberBetween(900000, 999999),
            'estado' => EstadoRecepcionPlanta::Borrador->value,
            'fecha' => '2026-07-30',
            'planta_proveedor_id' => null,
            'planta_ubicacion_id' => PlantaUbicacion::factory(),
            'documento_referencia' => null,
            'creado_por' => null,
            'confirmado_por' => null,
            'confirmado_en' => null,
            'responsable_user_id' => null,
            'responsable_nombre' => null,
            'observaciones' => null,
            'reversion_de_id' => null,
            'revertido_por_id' => null,
        ];
    }

    public function anulada(): static
    {
        return $this->state(fn () => ['estado' => EstadoRecepcionPlanta::Anulada->value]);
    }
}
