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
            'direccion' => $this->faker->streetAddress(), // el JSON del MH exige complemento no vacío
            'activo' => true,
        ];
    }

    /** Contribuyente nacional (recibe CCF): NIT + NRC + ubicación/actividad (receptor válido). */
    public function contribuyente(): static
    {
        return $this->state(fn () => [
            'tipo_cliente' => TipoCliente::Contribuyente->value,
            'tipo_persona' => TipoPersona::Juridica->value,
            'tipo_documento' => TipoDocumentoCliente::Nit->value,
            'num_documento' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'nombre' => $this->faker->company(),
            // Datos que exige el receptor de un CCF/NC (si los catálogos ya están seedeados).
            'actividad_economica_id' => \App\Models\ActividadEconomica::query()->value('id'),
            'departamento_id' => \App\Models\Departamento::query()->value('id'),
            'municipio_id' => \App\Models\Municipio::query()->value('id'),
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
            // País extranjero (≠ El Salvador 9300): lo exige el receptor de exportación.
            'pais_id' => \App\Models\Pais::query()->where('codigo', '!=', '9300')->value('id'),
        ]);
    }
}
