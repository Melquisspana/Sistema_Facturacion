<?php

namespace Database\Seeders;

use App\Enums\RolSistema;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Usuario ADMINISTRADOR inicial TEMPORAL para pruebas/desarrollo.
 *
 * ⚠️ Es un acceso de desarrollo: en PRODUCCIÓN se debe cambiar la contraseña de
 * inmediato (o eliminar este usuario) y administrar los accesos desde el módulo
 * Usuarios.
 *
 * Seguro e IDEMPOTENTE:
 *  - Crea el usuario solo si no existe (no duplica).
 *  - NO cambia la contraseña de un usuario existente, salvo que esté vacía.
 *  - No borra usuarios ni toca datos de facturación.
 *
 * Ejecutar:  php artisan db:seed --class=UsuarioAdminInicialSeeder
 */
class UsuarioAdminInicialSeeder extends Seeder
{
    private const NOMBRE = 'Administrador';
    private const EMAIL = 'admin@dulceslanegrita.test';
    private const PASSWORD = 'Admin#2026Temporal'; // temporal: cambiar en producción

    public function run(): void
    {
        // Garantiza que existan los roles (RolesSeeder es idempotente).
        $this->call(RolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::where('email', self::EMAIL)->first();

        if (! $admin) {
            // El cast 'hashed' del modelo hashea la contraseña automáticamente.
            $admin = User::create([
                'name' => self::NOMBRE,
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
                'activo' => true,
            ]);
        } elseif (blank($admin->password)) {
            // Solo se repone la contraseña si está vacía; no se pisa una existente.
            $admin->update(['password' => self::PASSWORD]);
        }

        if (! $admin->hasRole(RolSistema::Administrador->value)) {
            $admin->assignRole(RolSistema::Administrador->value);
        }
    }
}
