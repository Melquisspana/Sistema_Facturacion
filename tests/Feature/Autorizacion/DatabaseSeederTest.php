<?php

namespace Tests\Feature\Autorizacion;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `php artisan db:seed` en una instalación nueva (issue #25).
 *
 * DatabaseSeeder usa WithoutModelEvents, y spatie limpia su caché de permisos con los
 * eventos del modelo. Sin cuidar eso, la caché se quedaba con la lista vacía leída antes
 * de crear los permisos y RolesSeeder reventaba con PermissionDoesNotExist.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_en_una_base_nueva_deja_cada_rol_con_sus_permisos(): void
    {
        // TestCase ya siembra RolesSeeder en cada base fresca. Se vacían las tablas de
        // roles y permisos para quedar como recién migrado, igual que una instalación nueva.
        $tablas = config('permission.table_names');
        foreach (['role_has_permissions', 'model_has_permissions', 'model_has_roles', 'roles', 'permissions'] as $tabla) {
            DB::table($tablas[$tabla])->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('db:seed')->assertSuccessful();

        foreach (RolSistema::cases() as $rol) {
            $esperados = PermisoSistema::paraRol($rol);
            sort($esperados);

            $reales = Role::findByName($rol->value, 'web')
                ->permissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($esperados, $reales, "El rol {$rol->value} no tiene el set de permisos esperado.");
        }
    }
}
