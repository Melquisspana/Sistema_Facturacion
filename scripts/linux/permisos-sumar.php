<?php

// Permisos y roles SOLO SUMANDO, para desplegar en Linux. Uso (ver docs/DESPLIEGUE_LINUX.md):
//   docker compose --env-file .env.compose run --rm --no-deps -T -u www-data \
//       -v "$PWD/../fuente/scripts/linux:/kit:ro" web php /kit/permisos-sumar.php
// Crea los permisos de PermisoSistema que falten y le da a cada rol de RolSistema los que le
// faltan. NO usa RolesSeeder: su syncPermissions le quitaría a cada rol lo que se le dio a mano
// en producción. Lo que un rol tiene de más solo se lista; quitarlo es un paso aparte.
// Solo imprime nombres de roles y permisos.
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

chdir('/var/www/html');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$registro = app(PermissionRegistrar::class);
$registro->forgetCachedPermissions();
$creados = [];
foreach (PermisoSistema::todos() as $p) {
    if (! Permission::where('name', $p)->where('guard_name', 'web')->exists()) {
        Permission::findOrCreate($p, 'web');
        $creados[] = $p;
    }
}
$registro->forgetCachedPermissions();
echo 'permisos creados: '.(count($creados) ? implode(', ', $creados) : 'ninguno')."\n";

foreach (RolSistema::cases() as $rol) {
    $nuevo = ! Role::where('name', $rol->value)->where('guard_name', 'web')->exists();
    $role = Role::findOrCreate($rol->value, 'web');
    $tiene = $role->permissions()->pluck('name')->all();
    $debe = $rol->permisos();
    $faltan = array_values(array_diff($debe, $tiene));
    $sobran = array_values(array_diff($tiene, $debe));
    if ($faltan) {
        $role->givePermissionTo($faltan);
    }
    echo $rol->value.($nuevo ? ' (rol NUEVO)' : '').': +'.count($faltan).($faltan ? ' ['.implode(', ', $faltan).']' : '')
        .($sobran ? '; tiene de más y se deja: ['.implode(', ', $sobran).']' : '')."\n";
}
$registro->forgetCachedPermissions();
