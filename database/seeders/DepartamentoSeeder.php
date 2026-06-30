<?php

namespace Database\Seeders;

use App\Models\Departamento;
use Illuminate\Database\Seeder;

/**
 * Los 14 departamentos de El Salvador (CAT-012). Códigos oficiales y estables.
 */
class DepartamentoSeeder extends Seeder
{
    public function run(): void
    {
        $departamentos = [
            ['codigo' => '01', 'nombre' => 'Ahuachapán'],
            ['codigo' => '02', 'nombre' => 'Santa Ana'],
            ['codigo' => '03', 'nombre' => 'Sonsonate'],
            ['codigo' => '04', 'nombre' => 'Chalatenango'],
            ['codigo' => '05', 'nombre' => 'La Libertad'],
            ['codigo' => '06', 'nombre' => 'San Salvador'],
            ['codigo' => '07', 'nombre' => 'Cuscatlán'],
            ['codigo' => '08', 'nombre' => 'La Paz'],
            ['codigo' => '09', 'nombre' => 'Cabañas'],
            ['codigo' => '10', 'nombre' => 'San Vicente'],
            ['codigo' => '11', 'nombre' => 'Usulután'],
            ['codigo' => '12', 'nombre' => 'San Miguel'],
            ['codigo' => '13', 'nombre' => 'Morazán'],
            ['codigo' => '14', 'nombre' => 'La Unión'],
        ];

        foreach ($departamentos as $depto) {
            Departamento::updateOrCreate(
                ['codigo' => $depto['codigo']],
                ['nombre' => $depto['nombre'], 'activo' => true],
            );
        }
    }
}
