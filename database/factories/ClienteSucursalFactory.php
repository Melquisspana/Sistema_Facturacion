<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteSucursal>
 */
class ClienteSucursalFactory extends Factory
{
    protected $model = ClienteSucursal::class;

    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory()->contribuyente(),
            'nombre' => 'Sala '.$this->faker->city(),
            'direccion' => $this->faker->streetAddress(),
            'requiere_orden_compra' => null, // hereda del cliente
            'activo' => true,
        ];
    }
}
