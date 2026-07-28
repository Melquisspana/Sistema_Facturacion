<?php

use App\Http\Controllers\Planta\InsumoController;
use App\Http\Controllers\Planta\PlantaDashboardController;
use App\Http\Controllers\Planta\ProveedorController;
use App\Http\Controllers\Planta\UbicacionController;
use Illuminate\Support\Facades\Route;

/*
| Producción / Planta — área operativa AISLADA (nombre técnico «planta»,
| etiqueta visible «Producción»; ver config/planta.php).
|
| NO emite DTE, no genera JSON, no firma, no transmite, no toca correlativos y
| no envía correo. Fase 2 paso 3: dashboard + CRUD de los catálogos base
| (insumos, proveedores, ubicaciones). Todavía no hay inventario: ni saldos, ni
| movimientos, ni recepciones, traslados o ajustes.
|
| Tres candados en orden, todos de BACKEND:
|   1. auth               -> invitado va al login.
|   2. modulo.planta      -> 404 si PLANTA_ENABLED=false (para TODOS los roles,
|                            incluido administrador).
|   3. permission:planta.ver -> 403 para jefatura, facturación y contabilidad,
|                            también escribiendo la URL a mano.
|
| Dentro de los catálogos hay un cuarto y un quinto candado:
|   4. permission:planta.catalogos.ver       -> entrar a los listados.
|   5. permission:planta.catalogos.gestionar -> crear, editar y activar/desactivar.
| El rol `produccion` tiene el 4 pero NO el 5: lee los catálogos y no los toca.
|
| El selector superior de áreas y los botones de las vistas son solo
| presentación y NUNCA autorizan.
*/
Route::middleware(['auth', 'modulo.planta', 'permission:planta.ver'])
    ->prefix('planta')
    ->name('planta.')
    ->group(function () {
        Route::get('/', [PlantaDashboardController::class, 'index'])->name('dashboard');

        /*
        | Catálogos base. La escritura lleva `planta.catalogos.gestionar` INLINE en
        | cada ruta que muta (patrón de `exportaciones` en routes/web.php), porque
        | la regla del repo de declarar las rutas literales ANTES de las
        | paramétricas impide agruparlas en un solo `middleware()`.
        |
        | NO hay `destroy`: los catálogos no se eliminan desde la interfaz. La
        | acción visible es activar/desactivar, que conserva el historial.
        */
        Route::middleware('permission:planta.catalogos.ver')->group(function () {
            $gestionar = 'permission:planta.catalogos.gestionar';

            // Insumos.
            Route::get('insumos', [InsumoController::class, 'index'])->name('insumos.index');
            Route::get('insumos/crear', [InsumoController::class, 'create'])->middleware($gestionar)->name('insumos.create');
            Route::post('insumos', [InsumoController::class, 'store'])->middleware($gestionar)->name('insumos.store');
            Route::get('insumos/{insumo}/editar', [InsumoController::class, 'edit'])->middleware($gestionar)->name('insumos.edit');
            Route::put('insumos/{insumo}', [InsumoController::class, 'update'])->middleware($gestionar)->name('insumos.update');
            Route::patch('insumos/{insumo}/toggle-activo', [InsumoController::class, 'toggleActivo'])->middleware($gestionar)->name('insumos.toggle-activo');

            // Proveedores.
            Route::get('proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
            Route::get('proveedores/crear', [ProveedorController::class, 'create'])->middleware($gestionar)->name('proveedores.create');
            Route::post('proveedores', [ProveedorController::class, 'store'])->middleware($gestionar)->name('proveedores.store');
            Route::get('proveedores/{proveedor}/editar', [ProveedorController::class, 'edit'])->middleware($gestionar)->name('proveedores.edit');
            Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update'])->middleware($gestionar)->name('proveedores.update');
            Route::patch('proveedores/{proveedor}/toggle-activo', [ProveedorController::class, 'toggleActivo'])->middleware($gestionar)->name('proveedores.toggle-activo');

            // Ubicaciones.
            Route::get('ubicaciones', [UbicacionController::class, 'index'])->name('ubicaciones.index');
            Route::get('ubicaciones/crear', [UbicacionController::class, 'create'])->middleware($gestionar)->name('ubicaciones.create');
            Route::post('ubicaciones', [UbicacionController::class, 'store'])->middleware($gestionar)->name('ubicaciones.store');
            Route::get('ubicaciones/{ubicacion}/editar', [UbicacionController::class, 'edit'])->middleware($gestionar)->name('ubicaciones.edit');
            Route::put('ubicaciones/{ubicacion}', [UbicacionController::class, 'update'])->middleware($gestionar)->name('ubicaciones.update');
            Route::patch('ubicaciones/{ubicacion}/toggle-activo', [UbicacionController::class, 'toggleActivo'])->middleware($gestionar)->name('ubicaciones.toggle-activo');
        });
    });
