<?php

namespace Database\Seeders;

use App\Models\Pais;
use Illuminate\Database\Seeder;

/**
 * Países (CAT-020). Subconjunto inicial centrado en El Salvador y socios
 * comerciales relevantes para exportación.
 *
 * Los códigos siguen CAT-020; validar contra el catálogo oficial vigente del
 * MH antes de la Fase 2. La tabla es pequeña y fácil de corregir.
 */
class PaisSeeder extends Seeder
{
    public function run(): void
    {
        $paises = [
            ['codigo' => '9300', 'nombre' => 'El Salvador'],
            ['codigo' => '9320', 'nombre' => 'Estados Unidos'],
            ['codigo' => '9280', 'nombre' => 'Guatemala'],
            ['codigo' => '9120', 'nombre' => 'Honduras'],
            ['codigo' => '9160', 'nombre' => 'Nicaragua'],
            ['codigo' => '9040', 'nombre' => 'Costa Rica'],
            ['codigo' => '9200', 'nombre' => 'Panamá'],
            ['codigo' => '9440', 'nombre' => 'México'],
        ];

        foreach ($paises as $pais) {
            Pais::updateOrCreate(
                ['codigo' => $pais['codigo']],
                ['nombre' => $pais['nombre'], 'activo' => true],
            );
        }
    }
}
