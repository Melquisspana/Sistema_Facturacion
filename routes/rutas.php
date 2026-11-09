<?php

use App\Http\Controllers\Rutas\AsignacionSalasController;
use App\Http\Controllers\Rutas\MiPedidoController;
use App\Http\Controllers\Rutas\MiSalidaController;
use App\Http\Controllers\Rutas\PedidoSalaController;
use App\Http\Controllers\Rutas\PersonalRutaController;
use App\Http\Controllers\Rutas\PorEntregarController;
use App\Http\Controllers\Rutas\RutaCoberturaController;
use App\Http\Controllers\Rutas\RutaController;
use App\Http\Controllers\Rutas\RutaSalaController;
use App\Http\Controllers\Rutas\RutasDashboardController;
use App\Http\Controllers\Rutas\SalidaCcfController;
use App\Http\Controllers\Rutas\SalidaCierreController;
use App\Http\Controllers\Rutas\SalidaEntregaController;
use App\Http\Controllers\Rutas\SalidaGastoController;
use App\Http\Controllers\Rutas\SalidaLiquidacionController;
use App\Http\Controllers\Rutas\SalidaRutaController;
use App\Http\Controllers\Rutas\VentasController;
use Illuminate\Support\Facades\Route;

/*
| Rutas — la operación de campo: qué ruta atiende cada sala, cada cuánto se sale,
| qué salidas se hacen y quién va en ellas (docs/DISENO_MODULO_RUTAS_20260926.md).
|
| Qué NO hace: emitir DTE, generar JSON, firmar, transmitir, tocar correlativos o
| enviar correo. Tampoco toca PPQ ni Planta. El seguimiento de CCF (albarán, quedan,
| pago) vive en Cobros de supermercados (`/cobros`), no acá.
|
| Dos candados de BACKEND, en orden:
|   1. auth                     -> invitado va al login.
|   2. permission:rutas.ver     -> 403 para quien no tenga el permiso de entrada,
|                                  también escribiendo la URL a mano.
|
| Un tercer candado, INLINE en cada ruta que escribe:
|   3. permission:rutas.gestionar -> crear/editar rutas, su cobertura, asignar salas,
|                                  crear salidas y moverles el estado. Armar y terminar
|                                  salidas aceptan además los permisos propios del jefe
|                                  de vendedores (`rutas.salidas.armar|terminar`).
|
| El selector superior de áreas y los botones de las vistas son solo presentación
| y NUNCA autorizan.
|
| Ninguna pantalla de Rutas se guarda en el navegador (`no-store`): al tocar «atrás»
| después de marcar, cerrar o anotar un gasto, se vuelve a pedir y muestra lo hecho,
| no la copia vieja.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo), por eso
| `salidas/crear` se declara antes que `salidas/{salida}`.
*/

// El área se llamó «Cobros» y vivía en /rutas-cobros. Los enlaces guardados siguen
// llegando al mismo lugar.
Route::get('rutas-cobros/{resto?}', fn (?string $resto = null) => redirect('/rutas/'.($resto ?? ''), 301))
    ->where('resto', '.*');

Route::middleware(['auth', 'permission:rutas.entregas.registrar', 'cache.headers:no_store;private'])
    ->prefix('rutas/mi-salida')->name('rutas.mi-salida.')->scopeBindings()
    ->group(function () {
        Route::get('/', [MiSalidaController::class, 'index'])->name('index');
        Route::post('{salida}/gastos', [SalidaGastoController::class, 'store'])->name('gastos.store');
        Route::delete('{salida}/gastos/{gasto}', [SalidaGastoController::class, 'destroy'])->name('gastos.destroy');
        Route::get('{salida}/gastos/{gasto}/foto', [SalidaGastoController::class, 'foto'])->name('gastos.foto');
        Route::get('{salida}/cierre', [SalidaCierreController::class, 'cierre'])->name('cierre');
        Route::post('{salida}/cierre', [SalidaCierreController::class, 'cerrar'])->name('cerrar');
        Route::get('{salida}/cierre/{fecha}/pdf', [SalidaCierreController::class, 'liquidacion'])->where('fecha', '\d{4}-\d{2}-\d{2}')->name('liquidacion');
        Route::get('{salida}/terminar', [SalidaLiquidacionController::class, 'show'])->name('terminar');
        Route::post('{salida}/terminar', [SalidaLiquidacionController::class, 'liquidar'])->name('liquidar');
        Route::get('{salida}/terminar/pdf', [SalidaLiquidacionController::class, 'pdf'])->name('liquidacion-final');
        // El PDF de un CCF de la salida: solo de esta salida y solo si va en ella.
        Route::get('{salida}/ccf/{entrega}', [SalidaCcfController::class, 'show'])->name('ccf');
        Route::get('{salida}', [MiSalidaController::class, 'show'])->name('show');
        // «Empezar ruta»: la planificada pasa a en curso, solo quien va y desde el día de la salida.
        Route::post('{salida}/empezar', [MiSalidaController::class, 'empezar'])->name('empezar');
        Route::patch('{salida}/entregas/{entrega}', [MiSalidaController::class, 'update'])->name('update');
        Route::patch('{salida}/entregas/{entrega}/deshacer', [MiSalidaController::class, 'deshacer'])->name('deshacer');
        Route::post('{salida}/sala/{sucursal}', [MiSalidaController::class, 'sala'])->name('sala')->withoutScopedBindings();
        // «Sacar pedido» en una sala de la salida (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10). Que
        // vaya en la salida, en curso y para sacar pedido lo pide la policy; la sala, PedidosSala.
        Route::middleware('permission:rutas.pedidos.registrar')->name('pedidos.')->group(function () {
            Route::get('{salida}/sala/{sucursal}/pedido', [MiPedidoController::class, 'create'])->name('create')->withoutScopedBindings();
            Route::post('{salida}/sala/{sucursal}/pedido', [MiPedidoController::class, 'store'])->name('store')->withoutScopedBindings();
            Route::get('{salida}/pedidos/{pedido}/editar', [MiPedidoController::class, 'edit'])->name('edit');
            Route::put('{salida}/pedidos/{pedido}', [MiPedidoController::class, 'update'])->name('update');
            Route::patch('{salida}/pedidos/{pedido}/anular', [MiPedidoController::class, 'anular'])->name('anular');
        });
    });

/*
| «Por entregar» (06/11/2026): los CCF que faltan entregar, por ruta, para que el vendedor los
| marque sin que nadie arme la salida. Mira el vendedor y también la oficina (`rutas.ver`);
| marcar es solo del vendedor. El PDF, solo de un CCF de la lista. Deshacer usa la ruta de
| «Mi salida».
*/
Route::middleware(['auth', 'permission:rutas.entregas.registrar|rutas.ver', 'cache.headers:no_store;private'])
    ->prefix('rutas/por-entregar')->name('rutas.por-entregar.')
    ->group(function () {
        Route::get('/', [PorEntregarController::class, 'index'])->name('index');
        Route::get('{dte}/pdf', [PorEntregarController::class, 'pdf'])->name('pdf');
        Route::post('{dte}/marcar', [PorEntregarController::class, 'marcar'])->name('marcar')
            ->middleware('permission:rutas.entregas.registrar');
    });

Route::middleware(['auth', 'permission:rutas.ver', 'cache.headers:no_store;private'])
    ->prefix('rutas')
    ->name('rutas.')
    ->group(function () {
        Route::get('/', [RutasDashboardController::class, 'index'])->name('dashboard');
        Route::get('ventas', [VentasController::class, 'index'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.index');
        Route::get('ventas/excel', [VentasController::class, 'excel'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.excel');
        Route::get('ventas/pdf', [VentasController::class, 'pdf'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.pdf');

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
        Route::prefix('salidas/{salida}/gastos')->name('salidas.gastos.')->scopeBindings()->group(function () {
            Route::post('/', [SalidaGastoController::class, 'store'])->middleware('permission:rutas.gestionar')->name('store');
            Route::delete('{gasto}', [SalidaGastoController::class, 'destroy'])->middleware('permission:rutas.gestionar')->name('destroy');
            Route::get('{gasto}/foto', [SalidaGastoController::class, 'foto'])->name('foto');
        });
        // «Terminar día» y «Terminar ruta» de la oficina: también el jefe de vendedores
        // (`rutas.salidas.terminar`), aunque no haya ido. Reabrir un día sigue siendo solo
        // de `rutas.gestionar`, igual que los gastos de arriba.
        Route::prefix('salidas/{salida}/cierre')->name('salidas.')->middleware('permission:rutas.gestionar|rutas.salidas.terminar')->group(function () {
            Route::get('/', [SalidaCierreController::class, 'cierre'])->name('cierre');
            Route::post('/', [SalidaCierreController::class, 'cerrar'])->name('cerrar');
            Route::get('{fecha}/pdf', [SalidaCierreController::class, 'liquidacion'])->where('fecha', '\d{4}-\d{2}-\d{2}')->name('liquidacion');
            Route::post('{fecha}/reabrir', [SalidaCierreController::class, 'reabrir'])->where('fecha', '\d{4}-\d{2}-\d{2}')->name('reabrir')
                ->middleware('permission:rutas.gestionar');
        });
        // «Terminar ruta»: liquidación de la salida completa. También el vendedor, desde «Mi salida».
        Route::prefix('salidas/{salida}/terminar')->name('salidas.')->middleware('permission:rutas.gestionar|rutas.salidas.terminar')->group(function () {
            Route::get('/', [SalidaLiquidacionController::class, 'show'])->name('terminar');
            Route::post('/', [SalidaLiquidacionController::class, 'liquidar'])->name('liquidar');
            Route::get('pdf', [SalidaLiquidacionController::class, 'pdf'])->name('liquidacion-final');
        });
        // Armar una salida: la oficina (`rutas.gestionar`) o el jefe de vendedores
        // (`rutas.salidas.armar`, que no da nada más). Editarla y cancelarla siguen abajo.
        Route::get('salidas/crear', [SalidaRutaController::class, 'create'])->name('salidas.create')
            ->middleware('permission:rutas.gestionar|rutas.salidas.armar');
        Route::post('salidas', [SalidaRutaController::class, 'store'])->name('salidas.store')
            ->middleware('permission:rutas.gestionar|rutas.salidas.armar');
        Route::get('salidas/{salida}', [SalidaRutaController::class, 'show'])->name('salidas.show');
        // El PDF de un CCF de la salida, para verlo al tocar su tarjeta. Solo lectura.
        Route::get('salidas/{salida}/ccf/{entrega}', [SalidaCcfController::class, 'show'])->name('salidas.ccf')
            ->scopeBindings();
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
                Route::delete('{entrega}/facturado-despues', [SalidaEntregaController::class, 'deshacerFacturadoDespues'])->name('facturado-despues.destroy');
            });

        /*
        | Pedidos de sala: la lista y el detalle de la oficina.
        */
        Route::prefix('pedidos')->name('pedidos.')->middleware('permission:rutas.pedidos.ver')->group(function () {
            Route::get('/', [PedidoSalaController::class, 'index'])->name('index');
            Route::get('{pedido}', [PedidoSalaController::class, 'show'])->name('show');
            // La OC llega después por EDI: la agrega o corrige la oficina.
            Route::patch('{pedido}/orden-compra', [PedidoSalaController::class, 'ordenCompra'])->name('orden-compra')
                ->middleware('permission:rutas.gestionar');
        });

        /*
        | PERSONAL OPERATIVO. Sin `destroy`: una persona que ya fue en salidas no se
        | borra, se desactiva — igual que las rutas.
        |
        | Los datos de los empleados son solo de la administración (`planilla.ver`, decisión
        | de Melqui, 04/11/2026): jefe de vendedores, Jefatura y Facturación eligen a quién
        | va en una salida, pero no abren estas fichas.
        */
        Route::prefix('personal')->name('personal.')->middleware(['permission:planilla.ver', 'permission:rutas.personal.ver'])->group(function () {
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
