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
| «Atrás» (12/11/2026), dos casos:
|   - Las pantallas del vendedor que MARCAN cosas (Inicio, Terminar el día, Por entregar,
|     Sacar pedido y el pedido de una sala) y el detalle de un viaje, donde la oficina
|     anota entregas y gastos, no se guardan en el navegador (`no-store`): al tocar «atrás»
|     después de marcar se vuelven a pedir y muestran lo hecho. Una copia vieja llevaría a
|     marcar dos veces.
|   - Las de la oficina que solo se miran (Tablero, Viajes, Mapa, Pedidos, Zonas, Ventas y
|     Todos los viajes) usan `private, no-cache`: «atrás» las restaura al instante como
|     estaban, una visita nueva las pide frescas y ningún proxy las guarda. Al salir, el
|     logout manda `Clear-Site-Data: "cache"` para que «atrás» no las muestre sin sesión.
|
| Las rutas literales van ANTES que las paramétricas (regla del repo).
|
| Las pantallas que se retiraron ya no redirigen (11/11/2026): un enlace guardado de antes
| da «no encontrado».
*/

$sinCopia = 'cache.headers:no_store;private';
$atrasAlInstante = 'cache.headers:no_cache;private';

Route::middleware(['auth', 'permission:rutas.entregas.registrar', $sinCopia])
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
Route::middleware(['auth', 'permission:rutas.pedidos.registrar', $sinCopia])
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
Route::middleware(['auth', 'permission:rutas.entregas.registrar|rutas.ver', $sinCopia])
    ->prefix('rutas/por-entregar')->name('rutas.por-entregar.')
    ->group(function () {
        Route::get('/', [PorEntregarController::class, 'index'])->name('index');
        Route::get('{dte}/pdf', [PorEntregarController::class, 'pdf'])->name('pdf');
        // La hoja del pedido del CCF (el sugerido con la F), para que la firme el gerente.
        Route::get('{dte}/hoja', [PorEntregarController::class, 'hoja'])->name('hoja');
        Route::post('{dte}/marcar', [PorEntregarController::class, 'marcar'])->name('marcar')
            ->middleware('permission:rutas.entregas.registrar');
    });

// Pantallas de la oficina: cada bloque dice si «atrás» la restaura o la vuelve a pedir.
Route::middleware(['auth', 'permission:rutas.ver'])
    ->prefix('rutas')
    ->name('rutas.')
    ->group(function () use ($sinCopia, $atrasAlInstante) {
        // Solo se miran: «atrás» las restaura al instante.
        Route::middleware($atrasAlInstante)->group(function () {
            Route::get('/', [RutasDashboardController::class, 'index'])->name('dashboard');
            // La pestaña «Viajes» del tablero: en qué paso va cada pedido de cada viaje (10/11/2026).
            Route::get('viajes', [ViajesController::class, 'index'])->name('viajes');
            // La pestaña «Mapa» del tablero: ventas por zona, con montos (09/11/2026).
            Route::get('mapa', [MapaVentasController::class, 'index'])
                ->middleware('permission:rutas.mapa.ver')->name('mapa');
            Route::get('ventas', [VentasController::class, 'index'])
                ->middleware('permission:rutas.ventas.ver')->name('ventas.index');

            /*
            | «Zonas»: el catálogo de rutas, con su nombre y los lugares que cubre. La zona le pone
            | nombre al viaje y agrupa el tablero y el mapa. Sin `destroy`: una zona no se elimina,
            | se desactiva. Todo se hace en la misma página.
            */
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
        });

        // Las descargas de Ventas (Excel y PDF) no quedan en la caché del navegador, y Zonas
        // se edita en la misma página: si «atrás» la restaurara, un formulario viejo podría
        // volver a guardar un nombre o una cobertura que otro ya cambió. Van sin copia.
        Route::get('ventas/excel', [VentasController::class, 'excel'])
            ->middleware(['permission:rutas.ventas.ver', $sinCopia])->name('ventas.excel');
        Route::get('ventas/pdf', [VentasController::class, 'pdf'])
            ->middleware(['permission:rutas.ventas.ver', $sinCopia])->name('ventas.pdf');
        Route::get('rutas', [RutaController::class, 'index'])->middleware($sinCopia)->name('rutas.index');

        // El detalle de un viaje: la oficina anota entregas y gastos ahí; no se guarda (ver arriba).
        Route::prefix('salidas/{salida}/gastos')->name('salidas.gastos.')->middleware($sinCopia)->scopeBindings()->group(function () {
            Route::post('/', [SalidaGastoController::class, 'store'])->middleware('permission:rutas.gestionar')->name('store');
            Route::delete('{gasto}', [SalidaGastoController::class, 'destroy'])->middleware('permission:rutas.gestionar')->name('destroy');
            Route::get('{gasto}/foto', [SalidaGastoController::class, 'foto'])->name('foto');
        });
        // El PDF del día de quien va en un viaje, para la oficina y el jefe.
        Route::get('salidas/{salida}/dia/{fecha}/{persona}/pdf', [ViajesController::class, 'diaPdf'])
            ->where('fecha', '\d{4}-\d{2}-\d{2}')->middleware($sinCopia)->name('salidas.dia-pdf');
        Route::get('salidas/{salida}', [SalidaRutaController::class, 'show'])->middleware($sinCopia)->name('salidas.show');
        // El PDF de un CCF de la salida, para verlo al tocar su tarjeta. Solo lectura.
        Route::get('salidas/{salida}/ccf/{entrega}', [SalidaCcfController::class, 'show'])->name('salidas.ccf')
            ->middleware($sinCopia)->scopeBindings();
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
        | Pedidos de sala: la lista y la hoja se miran: «atrás» al instante. El detalle tiene el
        | formulario de la orden de compra: si «atrás» lo restaurara con una OC vieja, guardarlo
        | pisaría la que otro ya corrigió. Va sin copia.
        */
        Route::prefix('pedidos')->name('pedidos.')->middleware('permission:rutas.pedidos.ver')->group(function () use ($sinCopia, $atrasAlInstante) {
            Route::get('/', [PedidoSalaController::class, 'index'])->middleware($atrasAlInstante)->name('index');
            Route::get('{pedido}', [PedidoSalaController::class, 'show'])->middleware($sinCopia)->name('show');
            // La hoja de lo que salió: el sugerido en limpio, para imprimir. Sin precios.
            Route::get('{pedido}/hoja', [PedidoSalaController::class, 'hoja'])->middleware($atrasAlInstante)->name('hoja');
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
        | va en una salida, pero no abren estas fichas. Son datos de empleados: no se guardan.
        */
        Route::prefix('personal')->name('personal.')->middleware(['permission:planilla.ver', 'permission:rutas.personal.ver', $sinCopia])->group(function () {
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
