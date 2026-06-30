<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquesta la carga de todos los catálogos base del Ministerio de Hacienda.
 * Idempotente: cada seeder usa updateOrCreate.
 */
class CatalogosMhSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PaisSeeder::class,
            DepartamentoSeeder::class,
            MunicipioSeeder::class,
            DistritoSeeder::class,
            ActividadEconomicaSeeder::class,
            UnidadMedidaSeeder::class,
        ]);
    }
}
