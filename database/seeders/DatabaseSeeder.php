<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CatalogosMhSeeder::class,
            RolesSeeder::class,
        ]);

        // RolesSeeder ya deja cada rol con sus permisos. El administrador
        // inicial se crea aparte con UsuarioAdminInicialSeeder.
    }
}
