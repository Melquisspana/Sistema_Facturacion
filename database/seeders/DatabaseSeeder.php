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

        // El usuario administrador inicial y los permisos granulares se
        // sembrarán en la fase de seguridad/usuarios.
    }
}
