<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteSucursal>
 */
class ClienteSucursalFactory extends Factory
{
    protected $model = ClienteSucursal::class;

    public function definition(): array
    {
        // Ubicación completa como la de las salas reales: el receptor del CCF toma
        // departamento y municipio de la SALA cuando el documento tiene una (ver
        // MapeadorDteSalida::receptor y ValidacionPreJsonService), así que una sala
        // sin ubicación no representa ningún caso de producción.
        $departamentoId = Departamento::query()->value('id');

        return [
            'cliente_id' => Cliente::factory()->contribuyente(),
            'nombre' => 'Sala '.$this->faker->city(),
            'direccion' => $this->faker->streetAddress(),
            'departamento_id' => $departamentoId,
            'municipio_id' => Municipio::query()->where('departamento_id', $departamentoId)->value('id'),
            'requiere_orden_compra' => null, // hereda del cliente
            'activo' => true,
        ];
    }
}
