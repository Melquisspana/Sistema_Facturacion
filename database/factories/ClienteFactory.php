<?php

namespace Database\Factories;

use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPersona;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'tipo_cliente' => TipoCliente::ConsumidorFinal->value,
            'nombre' => $this->faker->name(),
            'correo' => $this->faker->safeEmail(),
            'telefono' => $this->faker->numerify('2###-####'),
            'activo' => true,
        ];
    }

    /** Contribuyente nacional (recibe CCF): NIT + NRC. */
    public function contribuyente(): static
    {
        return $this->state(fn () => [
            'tipo_cliente' => TipoCliente::Contribuyente->value,
            'tipo_persona' => TipoPersona::Juridica->value,
            'tipo_documento' => TipoDocumentoCliente::Nit->value,
            'num_documento' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'nombre' => $this->faker->company(),
        ]);
    }

    /** Cliente de exportación (FEX). */
    public function exportacion(): static
    {
        return $this->state(fn () => [
            'tipo_cliente' => TipoCliente::Exportacion->value,
            'tipo_persona' => TipoPersona::Juridica->value,
            'tipo_documento' => TipoDocumentoCliente::Pasaporte->value,
            'num_documento' => $this->faker->bothify('P########'),
            'nombre' => $this->faker->company(),
        ]);
    }
}
