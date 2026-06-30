<?php

namespace Database\Seeders;

use App\Models\ActividadEconomica;
use Illuminate\Database\Seeder;

/**
 * Actividades económicas (CAT-019, base CIIU). Subconjunto pensado para una
 * empresa de elaboración y venta de dulces, dejando preparado para agregar más.
 * Validar/ampliar contra el catálogo CAT-019 oficial antes de la Fase 2.
 */
class ActividadEconomicaSeeder extends Seeder
{
    public function run(): void
    {
        $actividades = [
            ['codigo' => '10730', 'nombre' => 'Elaboración de cacao, chocolate y de productos de confitería'],
            ['codigo' => '10711', 'nombre' => 'Elaboración de productos de panadería'],
            ['codigo' => '10790', 'nombre' => 'Elaboración de otros productos alimenticios n.c.p.'],
            ['codigo' => '46900', 'nombre' => 'Venta al por mayor de una variedad de artículos sin especialización'],
            ['codigo' => '47190', 'nombre' => 'Venta al por menor en comercios no especializados'],
        ];

        foreach ($actividades as $actividad) {
            ActividadEconomica::updateOrCreate(
                ['codigo' => $actividad['codigo']],
                ['nombre' => $actividad['nombre'], 'activo' => true],
            );
        }
    }
}
