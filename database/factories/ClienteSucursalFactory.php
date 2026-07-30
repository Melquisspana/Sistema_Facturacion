<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Support\Ubicacion\UbicacionCoherenteFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteSucursal>
 */
class ClienteSucursalFactory extends Factory
{
    protected $model = ClienteSucursal::class;

    public function definition(): array
    {
        // Ubicación completa y COHERENTE como la de las salas reales: el receptor del CCF
        // toma departamento, municipio y distrito de la SALA cuando el documento tiene una
        // (ver MapeadorDteSalida::receptor y ValidacionPreJsonService). Una sala sin
        // distrito, o con un distrito que no pertenece a su municipio, no representa ningún
        // caso de producción válido: el MH la rechaza.
        return [
            'cliente_id' => Cliente::factory()->contribuyente(),
            'nombre' => 'Sala '.$this->faker->city(),
            'direccion' => $this->faker->streetAddress(),
            ...UbicacionCoherenteFactory::tercia(),
            'requiere_orden_compra' => null, // hereda del cliente
            'activo' => true,
        ];
    }
}
