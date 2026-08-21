<?php

namespace Database\Factories\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsistenciaDispositivo>
 */
class AsistenciaDispositivoFactory extends Factory
{
    protected $model = AsistenciaDispositivo::class;

    /**
     * Token FIJO y conocido en las pruebas: lo que hay que poder escribir en una
     * cabecera es el token EN CLARO, y la tabla solo guarda su hash. Generarlo al
     * azar obligaría a cada test a acordarse de capturarlo antes de que se pierda.
     * Para cambiarlo está {@see conToken()}.
     */
    public const TOKEN_DE_PRUEBA = 'token-de-prueba-del-lector';

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'codigo' => 'lector-'.$this->faker->unique()->numberBetween(1, 99999),
            'nombre' => 'Lector de prueba',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN_DE_PRUEBA),
            'activo' => true,
        ];
    }

    /** Un lector con un token distinto (para probar que no se cruzan). */
    public function conToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => AsistenciaDispositivo::hashDeToken($token)]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
