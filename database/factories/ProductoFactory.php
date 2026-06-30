<?php

namespace Database\Factories;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'codigo' => 'P'.$this->faker->unique()->numberBetween(1000, 9999),
            'nombre' => $this->faker->words(3, true),
            'descripcion' => $this->faker->optional()->sentence(),
            'tipo_producto' => TipoProducto::Bien->value,
            'unidad_medida_id' => UnidadMedida::query()->inRandomOrder()->value('id'),
            'precio_unitario' => $this->faker->randomFloat(2, 0.25, 50),
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
            'maneja_inventario' => false,
            'activo' => true,
        ];
    }
}
