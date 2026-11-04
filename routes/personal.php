<?php

use App\Http\Controllers\Personal\AccesoController;
use App\Http\Controllers\Personal\PersonalController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

/*
| Personal: la lista única de empleados y la ficha de cada persona.
|
| Abre con Asistencia o con Planilla encendidas, así que no puede colgarse de
| `modulo.asistencia` ni de `modulo.planilla`: el controlador resuelve qué alcanza cada
| usuario. Las rutas de marcar y la API del lector no pasan por acá.
*/

Route::middleware('auth')
    ->prefix('personal')
    ->name('personal.')
    ->group(function () {
        Route::get('empleados', [PersonalController::class, 'index'])->name('empleados');
        // Solo lectura: personas que pueden estar cargadas dos veces, para revisarlas a mano.
        Route::get('repetidos', [PersonalController::class, 'repetidos'])->name('repetidos');
        Route::get('empleados/{persona}', [PersonalController::class, 'show'])
            ->where('persona', '[ap][0-9]+')->name('empleados.show');
    });

/*
| Acceso al sistema (antes, el módulo «Usuarios»). El de una persona se da y se cambia en su
| ficha; acá quedan la lista de quién entra, la cuenta externa y la página de cada cuenta sin
| ficha. Mismo candado de siempre: `usuarios.gestionar`. No depende de que Asistencia o
| Planilla estén encendidas: sin ellas, es el único lugar para crear un usuario.
*/
Route::middleware(['auth', 'permission:usuarios.gestionar'])->group(function () {
    Route::prefix('personal/accesos')->name('personal.accesos.')->controller(AccesoController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('nuevo', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{usuario}', 'show')->whereNumber('usuario')->name('show');
        Route::put('{usuario}', 'update')->whereNumber('usuario')->name('update');
        Route::patch('{usuario}/activo', 'toggleActivo')->whereNumber('usuario')->name('toggle-activo');
        Route::put('{usuario}/password', 'updatePassword')->whereNumber('usuario')->name('password');
        Route::delete('{usuario}', 'destroy')->whereNumber('usuario')->name('destroy');
    });

    // Las direcciones viejas de Usuarios no rompen favoritos: solo GET (Route::redirect
    // aceptaría cualquier método). Sus POST/PUT/PATCH/DELETE se quitaron.
    foreach (['usuarios' => '/personal/accesos', 'usuarios/create' => '/personal/accesos/nuevo'] as $vieja => $nueva) {
        Route::get($vieja, RedirectController::class)->defaults('destination', $nueva)->defaults('status', 301);
    }
    foreach (['usuarios/{usuario}', 'usuarios/{usuario}/edit', 'usuarios/{usuario}/password'] as $vieja) {
        Route::get($vieja, [AccesoController::class, 'redirigir'])->whereNumber('usuario');
    }
});
