<?php

use App\Http\Controllers\Fabrica\PedidoFabricaController;
use Illuminate\Support\Facades\Route;

/*
| Fábrica — lo que sale para cada pedido de sala (etapa 1, PR 11). La fábrica marca qué
| manda: todo, una parte o «No se trae», con deshacer. No mueve insumos ni existencias.
|
| NO depende del módulo Producción (`modulo.planta`, apagado en el servidor) ni de
| `planta.ver`, y Rutas no tiene interruptor: el candado es el permiso.
|
| Candados de BACKEND, en orden:
|   1. auth                              -> invitado va al login.
|   2. permission:fabrica.pedidos.mandar -> 403 para el resto, también con la URL a mano.
|   3. Policies dentro de PedidosFabrica  -> por mandar, reabrir, deshacer solo hoy.
|
| `no-store`, como Rutas: «atrás» vuelve a pedir la lista y no muestra una copia vieja.
| Sin rutas DELETE: un envío se anula, nunca se borra. Las literales van antes que las
| paramétricas.
*/
Route::middleware(['auth', 'permission:fabrica.pedidos.mandar', 'cache.headers:no_store;private'])
    ->prefix('fabrica/pedidos')->name('fabrica.pedidos.')
    ->group(function () {
        Route::get('/', [PedidoFabricaController::class, 'index'])->name('index');
        Route::get('{pedido}', [PedidoFabricaController::class, 'show'])->name('show');
        // Lo marcado en la hoja se guarda solo (fetch), sin mandar nada.
        Route::patch('{pedido}/borrador', [PedidoFabricaController::class, 'borrador'])->name('borrador');
        Route::patch('{pedido}/borrador/borrar', [PedidoFabricaController::class, 'borrarBorrador'])->name('borrador.borrar');
        Route::post('{pedido}/envios', [PedidoFabricaController::class, 'mandar'])->name('mandar');
        // La hoja para imprimir de un envío de ese pedido (solo lectura).
        Route::get('{pedido}/envios/{envio}/imprimir', [PedidoFabricaController::class, 'imprimir'])->name('imprimir')
            ->scopeBindings();
        Route::patch('{pedido}/no-se-trae', [PedidoFabricaController::class, 'cerrar'])->name('cerrar');
        Route::patch('{pedido}/pendiente', [PedidoFabricaController::class, 'reabrir'])->name('reabrir');
        Route::patch('{pedido}/envios/{envio}/anular', [PedidoFabricaController::class, 'anularEnvio'])->name('anular-envio')
            ->scopeBindings();
    });
