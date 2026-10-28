<?php

use App\Http\Controllers\Asistencia\AsistenciaDashboardController;
use App\Http\Controllers\Asistencia\EmpleadoController;
use App\Http\Controllers\Asistencia\EnrolamientoController;
use App\Http\Controllers\Asistencia\HuellaController;
use App\Http\Controllers\Asistencia\JornadaController;
use App\Http\Controllers\Asistencia\MarcacionController;
use App\Http\Controllers\Asistencia\ReporteController;
use Illuminate\Support\Facades\Route;

/*
| Control de Asistencia — pantallas WEB: Hoy, Personal y Reporte. El historial de
| marcaciones y las jornadas conservan sus rutas como «detalle».
|
| Los lectores se administran en Configuración (routes/lectores-asistencia.php).
| Los endpoints del ESP32 viven en routes/api.php (`api.asistencia.*`) y no se
| tocan: el firmware de producción apunta a esas URL, y AsistenciaSimpleTest las fija.
|
| ────────────────────────────── Candados, en orden ──────────────────────────────
|
|   1. auth                     -> invitado va al login.
|   2. modulo.asistencia        -> 404 si ASISTENCIA_ENABLED=false.
|   3. permission:asistencia.ver-> 403 para quien no tenga el permiso de entrada.
|   4. permission:asistencia.gestionar (inline)
|        crear y editar personas, activarlas, guardar y QUITAR huellas. Asignar
|        decide de quién van a ser las marcaciones que vengan después.
|
| Los botones de las vistas son PRESENTACIÓN y nunca autorizan.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo).
*/
Route::middleware(['auth', 'modulo.asistencia', 'permission:asistencia.ver'])
    ->prefix('asistencia')
    ->name('asistencia.')
    ->group(function () {
        $gestionar = 'permission:asistencia.gestionar';

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

        /*
        | ENROLAMIENTO desde la web. Solo dos verbos: pedir y cancelar.
        |
        | Completar una orden NO se puede desde acá — eso vive en /api y exige el
        | token del lector, que el navegador no conoce y la web nunca muestra. Una
        | persona pide el registro; el sensor es quien confirma que grabó.
        */
        Route::post('empleados/{empleado}/enrolamiento', [EnrolamientoController::class, 'store'])->middleware($gestionar)->name('empleados.enrolamiento.store');
        Route::delete('empleados/{empleado}/enrolamiento/{orden}', [EnrolamientoController::class, 'destroy'])->middleware($gestionar)->name('empleados.enrolamiento.destroy');
        Route::patch('huellas/{huella}/liberar', [HuellaController::class, 'liberar'])->middleware($gestionar)->name('huellas.liberar');

        /*
        | HISTORIAL DE MARCACIONES. SOLO `index`: no hay `edit`, ni `update`, ni
        | `destroy`, y no es un olvido. Una marcación es un hecho ya ocurrido; la
        | tabla ni siquiera tiene `updated_at` con el que disimular un cambio.
        | Cuando exista la corrección manual será una fila NUEVA con
        | `origen = 'manual'`, nunca una edición encima del hecho.
        |
        | Basta `asistencia.ver`: consultar el historial no escribe nada.
        */
        Route::get('marcaciones', [MarcacionController::class, 'index'])->name('marcaciones.index');

        /*
        | JORNADAS. El reporte: qué ocurrió cada día, con las entradas y salidas
        | emparejadas y el tiempo de presencia sumado.
        |
        | Solo `index`, y por el mismo motivo que el historial: una jornada es un
        | objeto DERIVADO de las marcaciones. No se guarda, no tiene tabla y no se
        | puede editar — corregirla es corregir las marcaciones que la produjeron.
        |
        | Basta `asistencia.ver`: consultar no escribe nada.
        */
        Route::get('jornadas', [JornadaController::class, 'index'])->name('jornadas.index');

        Route::get('reporte', [ReporteController::class, 'index'])->name('reporte');
        Route::get('reporte/excel', [ReporteController::class, 'excel'])->name('reporte.excel');

        // Compatibilidad para los enlaces web guardados; las escrituras ya no existen aquí.
        Route::get('dispositivos', fn () => redirect()->route('configuracion.lectores.index', [], 301));
        Route::get('dispositivos/{dispositivo}/editar', fn (string $dispositivo) => redirect()->route('configuracion.lectores.edit', $dispositivo, 301));
    });
