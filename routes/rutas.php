<?php

use App\Http\Controllers\Rutas\MapaVentasController;
use App\Http\Controllers\Rutas\MiPedidoController;
use App\Http\Controllers\Rutas\MiSalidaController;
use App\Http\Controllers\Rutas\PedidoSalaController;
use App\Http\Controllers\Rutas\PersonalRutaController;
use App\Http\Controllers\Rutas\PorEntregarController;
use App\Http\Controllers\Rutas\RutaCoberturaController;
use App\Http\Controllers\Rutas\RutaController;
use App\Http\Controllers\Rutas\RutasDashboardController;
use App\Http\Controllers\Rutas\SalidaCcfController;
use App\Http\Controllers\Rutas\SalidaEntregaController;
use App\Http\Controllers\Rutas\SalidaGastoController;
use App\Http\Controllers\Rutas\SalidaRutaController;
use App\Http\Controllers\Rutas\VentasController;
use App\Http\Controllers\Rutas\ViajesController;
use App\Models\Ruta;
use Illuminate\Support\Facades\Route;

/*
| Rutas — la operación de campo. Desde el 10/11/2026 el viaje se arma solo con el primer
| pedido del vendedor (docs/decisiones/0010-el-viaje-reemplaza-a-la-ruta-fija.md): nadie arma
| salidas ni asigna salas, y las rutas quedan como catálogo de zonas. Las salidas armadas a
| mano de antes siguen como historial, solo para leer: ya no se cierran días ni se liquidan
| (11/11/2026).
|
| Qué NO hace: emitir DTE, generar JSON, firmar, transmitir, tocar correlativos o
| enviar correo. Tampoco toca PPQ ni Planta. El seguimiento de CCF (albarán, quedan,
| pago) vive en Facturas por cobrar (`/cobros`, barra de Facturación), no acá.
|
| Dos candados de BACKEND, en orden:
|   1. auth                     -> invitado va al login.
|   2. permission:rutas.ver     -> 403 para quien no tenga el permiso de entrada,
|                                  también escribiendo la URL a mano.
|
| Un tercer candado, INLINE en cada ruta que escribe:
|   3. permission:rutas.gestionar -> crear/editar zonas y su cobertura, y corregir las
|                                  salidas.
|
| El selector superior de áreas y los botones de las vistas son solo presentación
| y NUNCA autorizan.
|
| Ninguna pantalla de Rutas se guarda en el navegador (`no-store`): al tocar «atrás»
| después de marcar, cerrar o anotar un gasto, se vuelve a pedir y muestra lo hecho,
| no la copia vieja.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo).
|
| Las pantallas que se retiraron ya no redirigen (11/11/2026): un enlace guardado de antes
| da «no encontrado».
*/

Route::middleware(['auth', 'permission:rutas.entregas.registrar', 'cache.headers:no_store;private'])
    ->prefix('rutas/mi-salida')->name('rutas.mi-salida.')->scopeBindings()
    ->group(function () {
        Route::get('/', [MiSalidaController::class, 'index'])->name('index');
        // «Terminar el día» del vendedor: su resumen y el PDF (antes que las de {salida}).
        Route::get('dia', [MiSalidaController::class, 'dia'])->name('dia');
        Route::get('dia/pdf', [MiSalidaController::class, 'diaPdf'])->name('dia.pdf');
        Route::post('{salida}/gastos', [SalidaGastoController::class, 'store'])->name('gastos.store');
        Route::delete('{salida}/gastos/{gasto}', [SalidaGastoController::class, 'destroy'])->name('gastos.destroy');
        Route::get('{salida}/gastos/{gasto}/foto', [SalidaGastoController::class, 'foto'])->name('gastos.foto');
        Route::patch('{salida}/entregas/{entrega}/deshacer', [MiSalidaController::class, 'deshacer'])->name('deshacer');
        // «Terminar el día» del viaje: ¿mañana sigue en la misma zona? Si no, deja de sumar pedidos.
        Route::post('{salida}/terminar-dia', [MiSalidaController::class, 'terminarDia'])->name('terminar-dia');
    });

/*
| «Sacar pedido» (10/11/2026): el vendedor busca cualquier sala y saca el pedido sin salida
| armada; el pedido se suma solo a su viaje. Que tenga su ficha de Rutas, que la sala esté
| activa y quién corrige lo deciden la policy y PedidosSala.
*/
Route::middleware(['auth', 'permission:rutas.pedidos.registrar', 'cache.headers:no_store;private'])
    ->prefix('rutas/sacar-pedido')->name('rutas.sacar-pedido.')
    ->group(function () {
        Route::get('/', [MiPedidoController::class, 'index'])->name('index');
        // La lista que aparece mientras escribe (12/11/2026). Con límite: se consulta al teclear.
        Route::get('salas', [MiPedidoController::class, 'salas'])->name('salas')->middleware('throttle:60,1');
        // Tienda nueva: solo el nombre; la oficina la da de alta y la enlaza al facturar.
        Route::get('tienda-nueva', [MiPedidoController::class, 'createTiendaNueva'])->name('tienda-nueva.create');
        Route::post('tienda-nueva', [MiPedidoController::class, 'storeTiendaNueva'])->name('tienda-nueva.store');
        Route::get('sala/{sucursal}', [MiPedidoController::class, 'create'])->name('create');
        Route::post('sala/{sucursal}', [MiPedidoController::class, 'store'])->name('store');
        Route::get('pedidos/{pedido}/editar', [MiPedidoController::class, 'edit'])->name('edit');
        Route::put('pedidos/{pedido}', [MiPedidoController::class, 'update'])->name('update');
        Route::patch('pedidos/{pedido}/anular', [MiPedidoController::class, 'anular'])->name('anular');
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
        // La hoja del pedido del CCF (el sugerido con la F), para que la firme el gerente.
        Route::get('{dte}/hoja', [PorEntregarController::class, 'hoja'])->name('hoja');
        Route::post('{dte}/marcar', [PorEntregarController::class, 'marcar'])->name('marcar')
            ->middleware('permission:rutas.entregas.registrar');
    });

Route::middleware(['auth', 'permission:rutas.ver', 'cache.headers:no_store;private'])
    ->prefix('rutas')
    ->name('rutas.')
    ->group(function () {
        Route::get('/', [RutasDashboardController::class, 'index'])->name('dashboard');
        // La pestaña «Viajes» del tablero: en qué paso va cada pedido de cada viaje (10/11/2026).
        Route::get('viajes', [ViajesController::class, 'index'])->name('viajes');
        // La pestaña «Mapa» del tablero: ventas por zona, con montos (09/11/2026).
        Route::get('mapa', [MapaVentasController::class, 'index'])
            ->middleware('permission:rutas.mapa.ver')->name('mapa');
        Route::get('ventas', [VentasController::class, 'index'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.index');
        Route::get('ventas/excel', [VentasController::class, 'excel'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.excel');
        Route::get('ventas/pdf', [VentasController::class, 'pdf'])
            ->middleware('permission:rutas.ventas.ver')->name('ventas.pdf');

        /*
        | «Zonas»: el catálogo de rutas, con su nombre y los lugares que cubre. La zona le pone
        | nombre al viaje y agrupa el tablero y el mapa. Sin `destroy`: una zona no se elimina,
        | se desactiva. Todo se hace en la misma página.
        */
        Route::get('rutas', [RutaController::class, 'index'])->name('rutas.index');
        Route::post('rutas', [RutaController::class, 'store'])->name('rutas.store')
            ->middleware('permission:rutas.gestionar');
        Route::put('rutas/{ruta}', [RutaController::class, 'update'])->name('rutas.update')
            ->middleware('permission:rutas.gestionar');
        Route::patch('rutas/{ruta}/toggle-activa', [RutaController::class, 'toggleActiva'])->name('rutas.toggle-activa')
            ->middleware('permission:rutas.gestionar');

        /*
        | Cobertura: departamentos completos y distritos sueltos de la zona. Con ella se sabe
        | de qué zona es una sala que no tiene una puesta.
        */
        Route::post('rutas/{ruta}/cobertura', [RutaCoberturaController::class, 'store'])->name('rutas.cobertura.store')
            ->middleware('permission:rutas.gestionar');
        Route::delete('rutas/{ruta}/cobertura/{cobertura}', [RutaCoberturaController::class, 'destroy'])->name('rutas.cobertura.destroy')
            ->middleware('permission:rutas.gestionar')
            ->scopeBindings();

        /*
        | «Todos los viajes»: los viajes y las salidas de antes, como historial. El viaje se
        | cierra solo; desde acá la oficina solo lo cancela si se abrió por error.
        */
        Route::get('salidas', [SalidaRutaController::class, 'index'])->name('salidas.index');
        Route::prefix('salidas/{salida}/gastos')->name('salidas.gastos.')->scopeBindings()->group(function () {
            Route::post('/', [SalidaGastoController::class, 'store'])->middleware('permission:rutas.gestionar')->name('store');
            Route::delete('{gasto}', [SalidaGastoController::class, 'destroy'])->middleware('permission:rutas.gestionar')->name('destroy');
            Route::get('{gasto}/foto', [SalidaGastoController::class, 'foto'])->name('foto');
        });
        // El PDF del día de quien va en un viaje, para la oficina y el jefe.
        Route::get('salidas/{salida}/dia/{fecha}/{persona}/pdf', [ViajesController::class, 'diaPdf'])
            ->where('fecha', '\d{4}-\d{2}-\d{2}')->name('salidas.dia-pdf');
        Route::get('salidas/{salida}', [SalidaRutaController::class, 'show'])->name('salidas.show');
        // El PDF de un CCF de la salida, para verlo al tocar su tarjeta. Solo lectura.
        Route::get('salidas/{salida}/ccf/{entrega}', [SalidaCcfController::class, 'show'])->name('salidas.ccf')
            ->scopeBindings();
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
                Route::post('/', [SalidaEntregaController::class, 'store'])->name('store');
                Route::patch('{entrega}', [SalidaEntregaController::class, 'update'])->name('update');
                Route::patch('{entrega}/deshacer', [SalidaEntregaController::class, 'deshacer'])->name('deshacer');
                Route::delete('{entrega}', [SalidaEntregaController::class, 'destroy'])->name('destroy');
            });

        /*
        | Pedidos de sala: la lista y el detalle de la oficina.
        */
        Route::prefix('pedidos')->name('pedidos.')->middleware('permission:rutas.pedidos.ver')->group(function () {
            Route::get('/', [PedidoSalaController::class, 'index'])->name('index');
            Route::get('{pedido}', [PedidoSalaController::class, 'show'])->name('show');
            // La hoja de lo que salió: el sugerido en limpio, para imprimir. Sin precios.
            Route::get('{pedido}/hoja', [PedidoSalaController::class, 'hoja'])->name('hoja');
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
