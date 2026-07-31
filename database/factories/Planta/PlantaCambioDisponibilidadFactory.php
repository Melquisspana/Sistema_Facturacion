<?php

namespace Database\Factories\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaCambioDisponibilidad>
 *
 * Crea BORRADORES. No hay estado `confirmado()` a propósito: confirmar es lo que
 * emite el par de movimientos, y una factory que pusiera el estado a mano
 * dejaría un documento confirmado sin movimientos —justo la corrupción que la
 * reconciliación tiene que ir a buscar—. Para tener uno confirmado en una prueba
 * se llama a PlantaCambioDisponibilidadService.
 */
class PlantaCambioDisponibilidadFactory extends Factory
{
    protected $model = PlantaCambioDisponibilidad::class;

    public function definition(): array
    {
        $insumo = PlantaInsumo::factory();

        return [
            // El número lo asigna el servicio con Secuencia; aquí uno alto y
            // único para no chocar con la serie real en pruebas de esquema.
            'numero' => $this->faker->unique()->numberBetween(900000, 999999),
            'estado' => EstadoCambioDisponibilidad::Borrador->value,
            'planta_insumo_id' => $insumo,
            'planta_lote_id' => PlantaLote::factory(),
            'planta_ubicacion_id' => PlantaUbicacion::factory(),
            // El origen es siempre retenido: es lo único que este documento mueve.
            'estado_origen' => EstadoDisponibilidad::Retenido->value,
            'estado_destino' => EstadoDisponibilidad::Disponible->value,
            'cantidad' => '10.0000',
            'fecha' => '2026-07-30',
            'motivo' => 'Revisión de calidad superada',
            'creado_por' => null,
            'confirmado_por' => null,
            'confirmado_en' => null,
            'responsable_user_id' => null,
            'responsable_nombre' => null,
            'reversion_de_id' => null,
            'revertido_por_id' => null,
        ];
    }

    /** Rechazo en vez de liberación. */
    public function rechazo(): static
    {
        return $this->state(fn () => [
            'estado_destino' => EstadoDisponibilidad::Rechazado->value,
            'motivo' => 'Producto fuera de especificación',
        ]);
    }

    public function anulado(): static
    {
        return $this->state(fn () => ['estado' => EstadoCambioDisponibilidad::Anulado->value]);
    }
}
