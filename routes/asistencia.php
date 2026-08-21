<?php

use App\Http\Controllers\Asistencia\AsistenciaDashboardController;
use App\Http\Controllers\Asistencia\DispositivoController;
use App\Http\Controllers\Asistencia\EmpleadoController;
use App\Http\Controllers\Asistencia\HuellaController;
use Illuminate\Support\Facades\Route;

/*
| Control de Asistencia — pantallas WEB de administración.
|
| Qué contiene HOY: personas que marcan, sus asignaciones de ranura del sensor, y
| los lectores dados de alta.
|
| Qué NO contiene, y no por descuido: historial de marcaciones con filtros,
| reportes diarios o mensuales, cálculo de horas y enrolamiento remoto del ESP32.
| Cada uno tiene su fase. Ofrecer media pantalla de historial sería peor que no
| ofrecerla.
|
| NO se toca DTE, facturación, PPQ, exportaciones, Rutas/Cobros ni Planta.
|
| ─────────────────────────── Los endpoints del lector ───────────────────────────
|
| Viven aparte, en routes/api.php (`api.asistencia.*`), y no se tocan acá. El
| ESP32 no tiene sesión ni CSRF; estas pantallas exigen las dos. Son dos clientes
| distintos con dos candados distintos sobre el mismo módulo.
|
| ────────────────────────────── Candados, en orden ──────────────────────────────
|
|   1. auth                     -> invitado va al login.
|   2. modulo.asistencia        -> 404 si ASISTENCIA_ENABLED=false. En un servidor
|                                  sin lector, estas pantallas NO EXISTEN: ni la
|                                  URL escrita a mano, ni el enlace del selector
|                                  de áreas (App\Enums\AreaSistema::habilitada()).
|   3. permission:asistencia.ver-> 403 para quien no tenga el permiso de entrada.
|
| Y dos candados INLINE, porque son riesgos distintos:
|
|   4. permission:asistencia.gestionar
|        crear y editar personas, activarlas, y ASIGNAR o LIBERAR ranuras.
|        Asignar decide de quién van a ser las marcaciones que vengan después.
|   5. permission:asistencia.dispositivos.gestionar
|        dar de alta lectores, editarlos, activarlos y ROTARLES el token.
|        Va aparte porque produce un secreto y porque quien administra personal no
|        tiene por qué poder dejar el lector de la puerta sin autenticar.
|
| El selector de áreas y los botones de las vistas son PRESENTACIÓN y nunca
| autorizan: ocultar un botón no impide escribir su URL.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo), por eso
| `empleados/crear` se declara antes que `empleados/{empleado}`.
*/
Route::middleware(['auth', 'modulo.asistencia', 'permission:asistencia.ver'])
    ->prefix('asistencia')
    ->name('asistencia.')
    ->group(function () {
        $gestionar = 'permission:asistencia.gestionar';
        $lectores = 'permission:asistencia.dispositivos.gestionar';

        Route::get('/', [AsistenciaDashboardController::class, 'index'])->name('dashboard');

        /*
        | PERSONAS. Sin `destroy`: borrar a alguien borra su historial laboral, y la
        | forma más sólida de impedirlo es que no exista el endpoint. Quien se va se
        | DESACTIVA.
        */
        Route::get('empleados', [EmpleadoController::class, 'index'])->name('empleados.index');
        Route::get('empleados/crear', [EmpleadoController::class, 'create'])->middleware($gestionar)->name('empleados.create');
        Route::post('empleados', [EmpleadoController::class, 'store'])->middleware($gestionar)->name('empleados.store');
        Route::get('empleados/{empleado}', [EmpleadoController::class, 'show'])->name('empleados.show');
        Route::get('empleados/{empleado}/editar', [EmpleadoController::class, 'edit'])->middleware($gestionar)->name('empleados.edit');
        Route::put('empleados/{empleado}', [EmpleadoController::class, 'update'])->middleware($gestionar)->name('empleados.update');
        Route::patch('empleados/{empleado}/estado', [EmpleadoController::class, 'toggleActivo'])->middleware($gestionar)->name('empleados.toggle-activo');

        /*
        | RANURAS DEL SENSOR. Se asignan desde la ficha de la persona, que es donde
        | «qué ranura es de quién» se entiende.
        |
        | Liberar es PATCH y no DELETE a propósito: no se borra nada. La asignación
        | se queda como registro histórico con su empleado y sus marcaciones; lo
        | único que cambia es que deja de estar vigente. Un DELETE prometería lo
        | contrario de lo que hace.
        */
        Route::post('empleados/{empleado}/huellas', [HuellaController::class, 'store'])->middleware($gestionar)->name('empleados.huellas.store');
        Route::patch('huellas/{huella}/liberar', [HuellaController::class, 'liberar'])->middleware($gestionar)->name('huellas.liberar');

        /*
        | LECTORES. El token se genera al dar de alta y se renueva rotándolo; nunca
        | se escribe a mano y nunca se puede volver a leer.
        |
        | La rotación es GET (pantalla de confirmación) + POST (el acto). No es un
        | botón con `confirm()`: rotar deja al lector de la puerta sin autenticar
        | hasta que alguien reprograme el firmware, y mientras tanto nadie marca.
        */
        Route::get('dispositivos', [DispositivoController::class, 'index'])->name('dispositivos.index');
        Route::get('dispositivos/crear', [DispositivoController::class, 'create'])->middleware($lectores)->name('dispositivos.create');
        Route::post('dispositivos', [DispositivoController::class, 'store'])->middleware($lectores)->name('dispositivos.store');
        Route::get('dispositivos/{dispositivo}/editar', [DispositivoController::class, 'edit'])->middleware($lectores)->name('dispositivos.edit');
        Route::put('dispositivos/{dispositivo}', [DispositivoController::class, 'update'])->middleware($lectores)->name('dispositivos.update');
        Route::patch('dispositivos/{dispositivo}/estado', [DispositivoController::class, 'toggleActivo'])->middleware($lectores)->name('dispositivos.toggle-activo');
        Route::get('dispositivos/{dispositivo}/rotar-token', [DispositivoController::class, 'confirmarRotacion'])->middleware($lectores)->name('dispositivos.rotar-token');
        Route::post('dispositivos/{dispositivo}/rotar-token', [DispositivoController::class, 'rotarToken'])->middleware($lectores)->name('dispositivos.rotar-token.ejecutar');
    });
