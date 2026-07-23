<?php

namespace Database\Seeders;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles base y sus permisos. Idempotente: se puede ejecutar varias veces
 * sin duplicar y se puede correr tras un despliegue para incorporar permisos
 * nuevos sin tocar a los usuarios ya asignados.
 *
 *  - Los permisos y su reparto por rol se toman de {@see PermisoSistema} (fuente
 *    única de verdad).
 *  - `syncPermissions` deja cada rol EXACTAMENTE con su set (agrega los que falten
 *    y quita los que ya no correspondan), sin tocar las asignaciones usuario↔rol.
 *  - El administrador recibe todos los permisos (acceso total).
 *
 * Ejecutar:  php artisan db:seed --class=RolesSeeder
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Limpia la caché de permisos de spatie antes de sembrar.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) Todos los permisos del catálogo (idempotente).
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        // 2) Roles + su set exacto de permisos.
        foreach (RolSistema::cases() as $rol) {
            $role = Role::findOrCreate($rol->value, 'web');
            $role->syncPermissions($rol->permisos());
        }

        // Refresca la caché para que los cambios queden disponibles de inmediato.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
