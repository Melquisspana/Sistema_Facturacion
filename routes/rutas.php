<?php

use App\Http\Controllers\Rutas\RutaController;
use App\Http\Controllers\Rutas\RutaSalaController;
use App\Http\Controllers\Rutas\RutasDashboardController;
use App\Http\Controllers\Rutas\SalidaDocumentoController;
use App\Http\Controllers\Rutas\SalidaRutaController;
use Illuminate\Support\Facades\Route;

/*
| Rutas / Cobros — área comercial de campo.
|
| Qué contiene HOY: el catálogo de rutas, la asignación de la ruta habitual de
| cada sala, y las salidas de ruta con sus participantes y su estado.
|
| Qué NO hace y no va a hacer: emitir DTE, generar JSON, firmar, transmitir,
| tocar correlativos o enviar correo. Tampoco toca PPQ ni Planta. El seguimiento
| de documentos (CCF, albaranes) llega en el bloque siguiente y todavía no existe
| ninguna ruta suya acá.
|
| Dos candados de BACKEND, en orden:
|   1. auth                     -> invitado va al login.
|   2. permission:rutas.ver     -> 403 para quien no tenga el permiso de entrada,
|                                  también escribiendo la URL a mano.
|
| Un tercer candado, INLINE en cada ruta que escribe:
|   3. permission:rutas.gestionar -> crear/editar rutas, asignar salas, crear
|                                  salidas y moverles el estado.
|
| El selector superior de áreas y los botones de las vistas son solo presentación
| y NUNCA autorizan.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo), por eso
| `salidas/crear` se declara antes que `salidas/{salida}`.
*/
Route::middleware(['auth', 'permission:rutas.ver'])
    ->prefix('rutas-cobros')
    ->name('rutas.')
    ->group(function () {
        Route::get('/', [RutasDashboardController::class, 'index'])->name('dashboard');

        /*
        | Catálogo de rutas. Sin `destroy`: una ruta no se elimina, se desactiva
        | (conserva las salas asignadas y el historial de salidas).
        */
        Route::get('rutas', [RutaController::class, 'index'])->name('rutas.index');
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
        Route::post('rutas/{ruta}/salas', [RutaSalaController::class, 'store'])->name('rutas.salas.store')
            ->middleware('permission:rutas.gestionar');
        Route::delete('rutas/{ruta}/salas/{sucursal}', [RutaSalaController::class, 'destroy'])->name('rutas.salas.destroy')
            ->middleware('permission:rutas.gestionar');

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
        | Documentos de una salida (seguimiento documental).
        |
        | TODAS escriben, así que TODAS llevan `rutas.gestionar` — menos las dos
        | pantallas de búsqueda (candidatos e histórico), que solo consultan y por
        | eso se sirven con `rutas.ver`... salvo que desde ellas se agrega, y quien
        | no puede agregar no tiene nada que hacer ahí: llevan `gestionar` también.
        |
        | Ninguna de estas rutas toca `dtes`, `ppq_albaranes`, correlativos, firma ni
        | transmisión. La entrega y la NC se LEEN de PPQ y de `dtes`; nunca se escriben.
        |
        | No se creó ningún permiso nuevo: `rutas.ver` y `rutas.gestionar` alcanzan.
        */
        Route::prefix('salidas/{salida}/documentos')
            ->name('salidas.documentos.')
            ->middleware('permission:rutas.gestionar')
            ->group(function () {
                // Literales antes que paramétricas (regla del repo).
                Route::get('candidatos', [SalidaDocumentoController::class, 'candidatos'])->name('candidatos');
                Route::post('/', [SalidaDocumentoController::class, 'store'])->name('store');

                Route::get('historico', [SalidaDocumentoController::class, 'historico'])->name('historico');
                Route::post('historico', [SalidaDocumentoController::class, 'storeHistorico'])->name('historico.store');

                Route::post('asociar-automatico', [SalidaDocumentoController::class, 'asociarAutomatico'])->name('asociar-automatico');
                Route::post('documentacion-fisica', [SalidaDocumentoController::class, 'documentacionFisica'])->name('documentacion-fisica');

                Route::delete('{documento}', [SalidaDocumentoController::class, 'destroy'])->name('destroy');
                Route::patch('{documento}/mover', [SalidaDocumentoController::class, 'mover'])->name('mover');
                Route::delete('{documento}/documentacion-fisica', [SalidaDocumentoController::class, 'quitarDocumentacionFisica'])->name('documentacion-fisica.destroy');
                Route::patch('{documento}/requiere-nc', [SalidaDocumentoController::class, 'requiereNc'])->name('requiere-nc');
                Route::delete('{documento}/requiere-nc', [SalidaDocumentoController::class, 'quitarRequiereNc'])->name('requiere-nc.destroy');
            });
    });
