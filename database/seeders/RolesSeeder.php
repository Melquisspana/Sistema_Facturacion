<?php

namespace Database\Seeders;

use App\Enums\RolSistema;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles base del sistema. Idempotente: se puede ejecutar varias veces
 * sin duplicar. Los permisos granulares por rol y el usuario administrador
 * inicial se definirán en la fase de seguridad/usuarios.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Limpia la caché de permisos de spatie antes de sembrar.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web');
        }
    }
}
