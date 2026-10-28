<?php

use App\Http\Controllers\Asistencia\DispositivoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:configuracion.gestionar', 'modulo.asistencia', 'permission:asistencia.dispositivos.gestionar'])
    ->prefix('configuracion/asistencia/lectores')->name('configuracion.lectores.')
    ->group(function () {
        Route::get('/', [DispositivoController::class, 'index'])->name('index');
        Route::get('crear', [DispositivoController::class, 'create'])->name('create');
        Route::post('/', [DispositivoController::class, 'store'])->name('store');
        Route::get('{dispositivo}/editar', [DispositivoController::class, 'edit'])->name('edit');
        Route::put('{dispositivo}', [DispositivoController::class, 'update'])->name('update');
        Route::patch('{dispositivo}/estado', [DispositivoController::class, 'toggleActivo'])->name('toggle-activo');
        Route::get('{dispositivo}/rotar-token', [DispositivoController::class, 'confirmarRotacion'])->name('rotar-token');
        Route::post('{dispositivo}/rotar-token', [DispositivoController::class, 'rotarToken'])->name('rotar-token.ejecutar');
    });
