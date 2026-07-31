<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaAjuste;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaAjuste>
 *
 * Crea BORRADORES. No hay estado `confirmado()` a propósito: confirmar es lo que
 * emite los movimientos, y una factory que pusiera el estado a mano dejaría un
 * ajuste confirmado sin movimientos —justo la corrupción que la reconciliación
 * tiene que ir a buscar—. Para tener uno confirmado se llama a
 * PlantaAjusteService.
 */
class PlantaAjusteFactory extends Factory
{
    protected $model = PlantaAjuste::class;

    public function definition(): array
    {
        return [
            // El número lo asigna el servicio con Secuencia; aquí uno alto y
            // único para no chocar con la serie real en pruebas de esquema.
            'numero' => $this->faker->unique()->numberBetween(900000, 999999),
            'estado' => EstadoAjustePlanta::Borrador->value,
            'tipo' => TipoAjuste::Positivo->value,
            'fecha' => '2026-07-30',
            'motivo' => 'Diferencia constatada en revisión de bodega',
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

    public function deTipo(TipoAjuste $tipo): static
    {
        return $this->state(fn () => ['tipo' => $tipo->value]);
    }

    public function anulado(): static
    {
        return $this->state(fn () => ['estado' => EstadoAjustePlanta::Anulado->value]);
    }
}
