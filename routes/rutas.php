<?php

use App\Http\Controllers\Rutas\AsignacionSalasController;
use App\Http\Controllers\Rutas\PersonalRutaController;
use App\Http\Controllers\Rutas\RutaCoberturaController;
use App\Http\Controllers\Rutas\RutaController;
use App\Http\Controllers\Rutas\RutaSalaController;
use App\Http\Controllers\Rutas\RutasDashboardController;
use App\Http\Controllers\Rutas\SalidaEntregaController;
use App\Http\Controllers\Rutas\SalidaRutaController;
use Illuminate\Support\Facades\Route;

/*
| Rutas — la operación de campo: qué ruta atiende cada sala, cada cuánto se sale,
| qué salidas se hacen y quién va en ellas (docs/DISENO_MODULO_RUTAS_20260926.md).
|
| Qué NO hace: emitir DTE, generar JSON, firmar, transmitir, tocar correlativos o
| enviar correo. Tampoco toca PPQ ni Planta. El seguimiento de CCF (albarán, quedan,
| pago) vive en Cobros Calleja (`/cobros`), no acá.
|
| Dos candados de BACKEND, en orden:
|   1. auth                     -> invitado va al login.
|   2. permission:rutas.ver     -> 403 para quien no tenga el permiso de entrada,
|                                  también escribiendo la URL a mano.
|
| Un tercer candado, INLINE en cada ruta que escribe:
|   3. permission:rutas.gestionar -> crear/editar rutas, su cobertura, asignar salas,
|                                  crear salidas y moverles el estado.
|
| El selector superior de áreas y los botones de las vistas son solo presentación
| y NUNCA autorizan.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo), por eso
| `salidas/crear` se declara antes que `salidas/{salida}`.
*/

// El área se llamó «Cobros» y vivía en /rutas-cobros. Los enlaces guardados siguen
// llegando al mismo lugar.
Route::get('rutas-cobros/{resto?}', fn (?string $resto = null) => redirect('/rutas/'.($resto ?? ''), 301))
    ->where('resto', '.*');

Route::middleware(['auth', 'permission:rutas.ver'])
    ->prefix('rutas')
    ->name('rutas.')
    ->group(function () {
        Route::get('/', [RutasDashboardController::class, 'index'])->name('dashboard');

        /*
        | Catálogo de rutas. Sin `destroy`: una ruta no se elimina, se desactiva
        | (conserva las salas asignadas y el historial de salidas).
        */
        Route::get('rutas', [RutaController::class, 'index'])->name('rutas.index');

        /*
        | Asignación de salas por cobertura: el sistema PROPONE la ruta de cada sala a
        | partir de lo que cubre cada ruta, y el usuario confirma las que quiere.
        */
        Route::get('rutas/asignacion', [AsignacionSalasController::class, 'index'])->name('asignacion.index');
        Route::post('rutas/asignacion', [AsignacionSalasController::class, 'aplicar'])->name('asignacion.aplicar')
            ->middleware('permission:rutas.gestionar');

        Route::get('rutas/crear', [RutaController::class, 'create'])->name('rutas.create')
            ->middleware('permission:rutas.gestionar');
        Route::post('rutas', [RutaController::class, 'store'])->name('rutas.store')
            ->middleware('permission:rutas.gestionar');
        Route::get('rutas/{ruta}', [RutaController::class, 'show'])->name('rutas.show');
        Route::get('rutas/{ruta}/editar', [RutaController::class, 'edit'])->name('rutas.edit')
            ->middleware('permission:rutas.gestionar');
        Route::put('rutas/{ruta}', [RutaController::class, 'update'])->name('rutas.update')
            ->middleware('permission:rutas.gestionar');
        Route::patch('rutas/{ruta}/toggle-activa', [RutaController::class, 'toggleActiva'])->name('rutas.toggle-activa')
            ->middleware('permission:rutas.gestionar');

        /*
        | Salas habituales de una ruta. Asignar y quitar son acciones sobre
        | `cliente_sucursales.ruta_id`: NO crean ni modifican salas, solo escriben
        | esa columna.
        */
        // Buscador instantáneo (solo consulta): nombre, código, cliente o pueblo.
        Route::get('salas/buscar', [RutaSalaController::class, 'buscar'])->name('salas.buscar');
        Route::post('rutas/{ruta}/salas', [RutaSalaController::class, 'store'])->name('rutas.salas.store')
            ->middleware('permission:rutas.gestionar');
        Route::delete('rutas/{ruta}/salas/{sucursal}', [RutaSalaController::class, 'destroy'])->name('rutas.salas.destroy')
            ->middleware('permission:rutas.gestionar');

        /*
        | Cobertura: departamentos completos y distritos sueltos que atiende la ruta.
        | No asigna nada por sí sola; alimenta la propuesta de asignación.
        */
        Route::post('rutas/{ruta}/cobertura', [RutaCoberturaController::class, 'store'])->name('rutas.cobertura.store')
            ->middleware('permission:rutas.gestionar');
        Route::delete('rutas/{ruta}/cobertura/{cobertura}', [RutaCoberturaController::class, 'destroy'])->name('rutas.cobertura.destroy')
            ->middleware('permission:rutas.gestionar')
            ->scopeBindings();

        /*
        | Salidas de ruta. Los cambios de estado son acciones con nombre propio
        | (iniciar / finalizar / cancelar), no un campo editable del formulario.
        */
        Route::get('salidas', [SalidaRutaController::class, 'index'])->name('salidas.index');
        Route::get('salidas/crear', [SalidaRutaController::class, 'create'])->name('salidas.create')
            ->middleware('permission:rutas.gestionar');
        Route::post('salidas', [SalidaRutaController::class, 'store'])->name('salidas.store')
            ->middleware('permission:rutas.gestionar');
        Route::get('salidas/{salida}', [SalidaRutaController::class, 'show'])->name('salidas.show');
        Route::get('salidas/{salida}/editar', [SalidaRutaController::class, 'edit'])->name('salidas.edit')
            ->middleware('permission:rutas.gestionar');
        Route::put('salidas/{salida}', [SalidaRutaController::class, 'update'])->name('salidas.update')
            ->middleware('permission:rutas.gestionar');
        Route::patch('salidas/{salida}/iniciar', [SalidaRutaController::class, 'iniciar'])->name('salidas.iniciar')
            ->middleware('permission:rutas.gestionar');
        Route::patch('salidas/{salida}/finalizar', [SalidaRutaController::class, 'finalizar'])->name('salidas.finalizar')
            ->middleware('permission:rutas.gestionar');
        Route::patch('salidas/{salida}/cancelar', [SalidaRutaController::class, 'cancelar'])->name('salidas.cancelar')
            ->middleware('permission:rutas.gestionar');

        /*
        | CCF de una salida y qué pasó con cada uno (docs/DISENO_MODULO_RUTAS_20260926.md).
        | Todo escribe, así que todo lleva `rutas.gestionar`. La regla está en EntregasCcf.
        */
        Route::prefix('salidas/{salida}/entregas')
            ->name('salidas.entregas.')
            ->middleware('permission:rutas.gestionar')
            ->scopeBindings()
            ->group(function () {
                // Literales antes que paramétricas (regla del repo).
                Route::post('cargar', [SalidaEntregaController::class, 'cargar'])->name('cargar');
                Route::post('sala/{sucursal}', [SalidaEntregaController::class, 'sala'])->name('sala')
                    ->withoutScopedBindings();
                Route::post('/', [SalidaEntregaController::class, 'store'])->name('store');
                Route::patch('{entrega}', [SalidaEntregaController::class, 'update'])->name('update');
                Route::patch('{entrega}/deshacer', [SalidaEntregaController::class, 'deshacer'])->name('deshacer');
                Route::delete('{entrega}', [SalidaEntregaController::class, 'destroy'])->name('destroy');
            });

        /*
        | PERSONAL OPERATIVO. Sin `destroy`: una persona que ya fue en salidas no se
        | borra, se desactiva — igual que las rutas.
        */
        Route::prefix('personal')->name('personal.')->middleware('permission:rutas.personal.ver')->group(function () {
            Route::get('/', [PersonalRutaController::class, 'index'])->name('index');
            // Literales antes que paramétricas (regla del repo).
            Route::get('crear', [PersonalRutaController::class, 'create'])
                ->middleware('permission:rutas.personal.gestionar')->name('create');
            Route::post('/', [PersonalRutaController::class, 'store'])
                ->middleware('permission:rutas.personal.gestionar')->name('store');
            Route::get('{personal}', [PersonalRutaController::class, 'show'])->name('show');
            Route::get('{personal}/editar', [PersonalRutaController::class, 'edit'])
                ->middleware('permission:rutas.personal.gestionar')->name('edit');
            Route::put('{personal}', [PersonalRutaController::class, 'update'])
                ->middleware('permission:rutas.personal.gestionar')->name('update');
            Route::patch('{personal}/toggle-activo', [PersonalRutaController::class, 'toggleActivo'])
                ->middleware('permission:rutas.personal.gestionar')->name('toggle-activo');
        });
    });
