<?php

use App\Http\Controllers\Personal\PersonalController;
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
