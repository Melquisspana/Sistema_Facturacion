<?php

use App\Http\Controllers\Planta\AjusteController;
use App\Http\Controllers\Planta\CambioDisponibilidadController;
use App\Http\Controllers\Planta\EmpaqueConfigController;
use App\Http\Controllers\Planta\InsumoController;
use App\Http\Controllers\Planta\PlantaDashboardController;
use App\Http\Controllers\Planta\PresentacionController;
use App\Http\Controllers\Planta\ProductoBaseController;
use App\Http\Controllers\Planta\ProveedorController;
use App\Http\Controllers\Planta\RecepcionController;
use App\Http\Controllers\Planta\TrasladoController;
use App\Http\Controllers\Planta\UbicacionController;
use Illuminate\Support\Facades\Route;

/*
| Producción / Planta — área operativa AISLADA (nombre técnico «planta»,
| etiqueta visible «Producción»; ver config/planta.php).
|
| NO emite DTE, no genera JSON, no firma, no transmite, no toca correlativos y
| no envía correo. Contiene el dashboard, el CRUD de los catálogos base
| (insumos, proveedores, ubicaciones, productos, presentaciones, empaques) y las
| RECEPCIONES de insumos, que son el primer documento que mueve inventario.
| Traslados, ajustes y cambios de disponibilidad todavía no existen.
|
| Tres candados en orden, todos de BACKEND:
|   1. auth               -> invitado va al login.
|   2. modulo.planta      -> 404 si PLANTA_ENABLED=false (para TODOS los roles,
|                            incluido administrador).
|   3. permission:planta.ver -> 403 para jefatura, facturación y contabilidad,
|                            también escribiendo la URL a mano.
|
| Dentro de los catálogos hay un cuarto y un quinto candado:
|   4. permission:planta.catalogos.ver       -> entrar a los listados.
|   5. permission:planta.catalogos.gestionar -> crear, editar y activar/desactivar.
| El rol `produccion` tiene el 4 pero NO el 5: lee los catálogos y no los toca.
|
| El selector superior de áreas y los botones de las vistas son solo
| presentación y NUNCA autorizan.
*/
Route::middleware(['auth', 'modulo.planta', 'permission:planta.ver'])
    ->prefix('planta')
    ->name('planta.')
    ->group(function () {
        Route::get('/', [PlantaDashboardController::class, 'index'])->name('dashboard');

        /*
        | Catálogos base. La escritura lleva `planta.catalogos.gestionar` INLINE en
        | cada ruta que muta (patrón de `exportaciones` en routes/web.php), porque
        | la regla del repo de declarar las rutas literales ANTES de las
        | paramétricas impide agruparlas en un solo `middleware()`.
        |
        | NO hay `destroy`: los catálogos no se eliminan desde la interfaz. La
        | acción visible es activar/desactivar, que conserva el historial.
        */
        Route::middleware('permission:planta.catalogos.ver')->group(function () {
            $gestionar = 'permission:planta.catalogos.gestionar';

            // Insumos.
            Route::get('insumos', [InsumoController::class, 'index'])->name('insumos.index');
            Route::get('insumos/crear', [InsumoController::class, 'create'])->middleware($gestionar)->name('insumos.create');
            Route::post('insumos', [InsumoController::class, 'store'])->middleware($gestionar)->name('insumos.store');
            Route::get('insumos/{insumo}/editar', [InsumoController::class, 'edit'])->middleware($gestionar)->name('insumos.edit');
            Route::put('insumos/{insumo}', [InsumoController::class, 'update'])->middleware($gestionar)->name('insumos.update');
            Route::patch('insumos/{insumo}/toggle-activo', [InsumoController::class, 'toggleActivo'])->middleware($gestionar)->name('insumos.toggle-activo');

            // Proveedores.
            Route::get('proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
            Route::get('proveedores/crear', [ProveedorController::class, 'create'])->middleware($gestionar)->name('proveedores.create');
            Route::post('proveedores', [ProveedorController::class, 'store'])->middleware($gestionar)->name('proveedores.store');
            Route::get('proveedores/{proveedor}/editar', [ProveedorController::class, 'edit'])->middleware($gestionar)->name('proveedores.edit');
            Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update'])->middleware($gestionar)->name('proveedores.update');
            Route::patch('proveedores/{proveedor}/toggle-activo', [ProveedorController::class, 'toggleActivo'])->middleware($gestionar)->name('proveedores.toggle-activo');

            // Ubicaciones.
            Route::get('ubicaciones', [UbicacionController::class, 'index'])->name('ubicaciones.index');
            Route::get('ubicaciones/crear', [UbicacionController::class, 'create'])->middleware($gestionar)->name('ubicaciones.create');
            Route::post('ubicaciones', [UbicacionController::class, 'store'])->middleware($gestionar)->name('ubicaciones.store');
            Route::get('ubicaciones/{ubicacion}/editar', [UbicacionController::class, 'edit'])->middleware($gestionar)->name('ubicaciones.edit');
            Route::put('ubicaciones/{ubicacion}', [UbicacionController::class, 'update'])->middleware($gestionar)->name('ubicaciones.update');
            Route::patch('ubicaciones/{ubicacion}/toggle-activo', [UbicacionController::class, 'toggleActivo'])->middleware($gestionar)->name('ubicaciones.toggle-activo');

            // Productos base.
            Route::get('productos-base', [ProductoBaseController::class, 'index'])->name('productos-base.index');
            Route::get('productos-base/crear', [ProductoBaseController::class, 'create'])->middleware($gestionar)->name('productos-base.create');
            Route::post('productos-base', [ProductoBaseController::class, 'store'])->middleware($gestionar)->name('productos-base.store');
            Route::get('productos-base/{productoBase}/editar', [ProductoBaseController::class, 'edit'])->middleware($gestionar)->name('productos-base.edit');
            Route::put('productos-base/{productoBase}', [ProductoBaseController::class, 'update'])->middleware($gestionar)->name('productos-base.update');
            Route::patch('productos-base/{productoBase}/toggle-activo', [ProductoBaseController::class, 'toggleActivo'])->middleware($gestionar)->name('productos-base.toggle-activo');

            // Presentaciones.
            Route::get('presentaciones', [PresentacionController::class, 'index'])->name('presentaciones.index');
            Route::get('presentaciones/crear', [PresentacionController::class, 'create'])->middleware($gestionar)->name('presentaciones.create');
            Route::post('presentaciones', [PresentacionController::class, 'store'])->middleware($gestionar)->name('presentaciones.store');
            Route::get('presentaciones/{presentacion}/editar', [PresentacionController::class, 'edit'])->middleware($gestionar)->name('presentaciones.edit');
            Route::put('presentaciones/{presentacion}', [PresentacionController::class, 'update'])->middleware($gestionar)->name('presentaciones.update');
            Route::patch('presentaciones/{presentacion}/toggle-activo', [PresentacionController::class, 'toggleActivo'])->middleware($gestionar)->name('presentaciones.toggle-activo');

            // Configuraciones de empaque. `predeterminada` va aparte de `update`
            // porque es un cambio de una sola bandera que además reordena a sus
            // hermanas; separarlo permite hacerlo desde el listado sin reenviar
            // el formulario entero.
            Route::get('empaques', [EmpaqueConfigController::class, 'index'])->name('empaques.index');
            Route::get('empaques/crear', [EmpaqueConfigController::class, 'create'])->middleware($gestionar)->name('empaques.create');
            Route::post('empaques', [EmpaqueConfigController::class, 'store'])->middleware($gestionar)->name('empaques.store');
            Route::get('empaques/{empaque}/editar', [EmpaqueConfigController::class, 'edit'])->middleware($gestionar)->name('empaques.edit');
            Route::put('empaques/{empaque}', [EmpaqueConfigController::class, 'update'])->middleware($gestionar)->name('empaques.update');
            Route::patch('empaques/{empaque}/predeterminada', [EmpaqueConfigController::class, 'marcarPredeterminada'])->middleware($gestionar)->name('empaques.predeterminada');
            Route::patch('empaques/{empaque}/toggle-activo', [EmpaqueConfigController::class, 'toggleActivo'])->middleware($gestionar)->name('empaques.toggle-activo');
        });

        /*
        | Recepciones de insumos. Primer documento del módulo que MUEVE
        | INVENTARIO, así que los permisos se reparten por acción y no por
        | pantalla:
        |
        |   - ver       -> planta.recepciones.ver
        |   - crear/editar/anular borradores -> planta.recepciones.crear
        |   - confirmar -> planta.recepciones.confirmar
        |   - reversar  -> planta.recepciones.reversar (admin en esta fase)
        |
        | El destino RETENIDO exige además `planta.calidad.gestionar`, pero eso
        | NO se declara aquí: no es una ruta distinta, es una condición sobre las
        | líneas que comprueba PlantaRecepcionService. Ponerlo en la ruta
        | impediría a producción confirmar también las recepciones normales.
        |
        | `anular` es PATCH y no DELETE a propósito: no se borra nada, se cambia
        | el estado a `anulada` y el documento queda para siempre. NO hay
        | `destroy`: ni siquiera un borrador desaparece de la base.
        |
        | Rutas literales ANTES que las paramétricas, como el resto del archivo.
        */
        Route::middleware('permission:planta.recepciones.ver')->group(function () {
            Route::get('recepciones', [RecepcionController::class, 'index'])->name('recepciones.index');
            Route::get('recepciones/crear', [RecepcionController::class, 'create'])
                ->middleware('permission:planta.recepciones.crear')->name('recepciones.create');
            Route::post('recepciones', [RecepcionController::class, 'store'])
                ->middleware('permission:planta.recepciones.crear')->name('recepciones.store');

            Route::get('recepciones/{recepcion}', [RecepcionController::class, 'show'])->name('recepciones.show');
            Route::get('recepciones/{recepcion}/editar', [RecepcionController::class, 'edit'])
                ->middleware('permission:planta.recepciones.crear')->name('recepciones.edit');
            Route::put('recepciones/{recepcion}', [RecepcionController::class, 'update'])
                ->middleware('permission:planta.recepciones.crear')->name('recepciones.update');
            Route::patch('recepciones/{recepcion}/anular', [RecepcionController::class, 'anular'])
                ->middleware('permission:planta.recepciones.crear')->name('recepciones.anular');
            Route::patch('recepciones/{recepcion}/confirmar', [RecepcionController::class, 'confirmar'])
                ->middleware('permission:planta.recepciones.confirmar')->name('recepciones.confirmar');
            Route::patch('recepciones/{recepcion}/reversar', [RecepcionController::class, 'reversar'])
                ->middleware('permission:planta.recepciones.reversar')->name('recepciones.reversar');
        });

        /*
        | Cambios de disponibilidad: liberar o rechazar saldo RETENIDO.
        |
        | LECTURA con `planta.existencias.ver`, no con `planta.ajustes.ver`. Un
        | ajuste altera la cantidad física y este documento no: lo que responde
        | es cuánto saldo es UTILIZABLE, que es una pregunta de existencias.
        | Colgarlo del permiso de ajustes reintroduciría la confusión que el
        | módulo separa a propósito. Además `planta.existencias.ver` ya existe y
        | producción ya lo tiene, así que no hubo que crear ningún permiso.
        |
        | ESCRITURA —crear, editar, anular, confirmar y reversar— con
        | `planta.calidad.gestionar`, que producción NO tiene: decidir qué saldo
        | entra en la operación no es tarea de quien lo mueve.
        |
        | `anular` es PATCH, no DELETE: no se borra nada, cambia el estado. NO hay
        | `destroy`.
        |
        | Rutas literales ANTES que las paramétricas, como el resto del archivo.
        */
        Route::middleware('permission:planta.existencias.ver')->group(function () {
            $gestionar = 'permission:planta.calidad.gestionar';

            Route::get('disponibilidad', [CambioDisponibilidadController::class, 'index'])->name('disponibilidad.index');
            Route::get('disponibilidad/crear', [CambioDisponibilidadController::class, 'create'])
                ->middleware($gestionar)->name('disponibilidad.create');
            Route::post('disponibilidad', [CambioDisponibilidadController::class, 'store'])
                ->middleware($gestionar)->name('disponibilidad.store');

            Route::get('disponibilidad/{disponibilidad}', [CambioDisponibilidadController::class, 'show'])->name('disponibilidad.show');
            Route::get('disponibilidad/{disponibilidad}/editar', [CambioDisponibilidadController::class, 'edit'])
                ->middleware($gestionar)->name('disponibilidad.edit');
            Route::put('disponibilidad/{disponibilidad}', [CambioDisponibilidadController::class, 'update'])
                ->middleware($gestionar)->name('disponibilidad.update');
            Route::patch('disponibilidad/{disponibilidad}/anular', [CambioDisponibilidadController::class, 'anular'])
                ->middleware($gestionar)->name('disponibilidad.anular');
            Route::patch('disponibilidad/{disponibilidad}/confirmar', [CambioDisponibilidadController::class, 'confirmar'])
                ->middleware($gestionar)->name('disponibilidad.confirmar');
            Route::patch('disponibilidad/{disponibilidad}/reversar', [CambioDisponibilidadController::class, 'reversar'])
                ->middleware($gestionar)->name('disponibilidad.reversar');
        });

        /*
        | Traslados entre ubicaciones. Permisos por ACCIÓN, no por pantalla,
        | porque enviar y recibir son actos físicos distintos que pueden recaer
        | en personas distintas:
        |
        |   - ver      -> planta.traslados.ver
        |   - crear / editar / cancelar borradores -> planta.traslados.crear
        |   - enviar   -> planta.traslados.enviar
        |   - recibir  -> planta.traslados.recibir
        |   - reversar -> planta.traslados.reversar (admin en esta fase)
        |
        | Producción tiene los cuatro primeros y NO el último: opera el día a
        | día, pero deshacer inventario ya contabilizado no es operación.
        |
        | `cancelar` es PATCH, no DELETE: no se borra nada, cambia el estado. NO
        | hay `destroy`.
        |
        | Rutas literales ANTES que las paramétricas, como el resto del archivo.
        */
        Route::middleware('permission:planta.traslados.ver')->group(function () {
            $crear = 'permission:planta.traslados.crear';

            Route::get('traslados', [TrasladoController::class, 'index'])->name('traslados.index');
            Route::get('traslados/crear', [TrasladoController::class, 'create'])
                ->middleware($crear)->name('traslados.create');
            Route::post('traslados', [TrasladoController::class, 'store'])
                ->middleware($crear)->name('traslados.store');

            Route::get('traslados/{traslado}', [TrasladoController::class, 'show'])->name('traslados.show');
            Route::get('traslados/{traslado}/editar', [TrasladoController::class, 'edit'])
                ->middleware($crear)->name('traslados.edit');
            Route::put('traslados/{traslado}', [TrasladoController::class, 'update'])
                ->middleware($crear)->name('traslados.update');
            Route::patch('traslados/{traslado}/cancelar', [TrasladoController::class, 'cancelar'])
                ->middleware($crear)->name('traslados.cancelar');
            Route::patch('traslados/{traslado}/enviar', [TrasladoController::class, 'enviar'])
                ->middleware('permission:planta.traslados.enviar')->name('traslados.enviar');
            Route::patch('traslados/{traslado}/recibir', [TrasladoController::class, 'recibir'])
                ->middleware('permission:planta.traslados.recibir')->name('traslados.recibir');
            Route::patch('traslados/{traslado}/reversar', [TrasladoController::class, 'reversar'])
                ->middleware('permission:planta.traslados.reversar')->name('traslados.reversar');
        });

        /*
        | Ajustes de inventario. Es el único documento que altera la cantidad
        | física sin contrapartida documental, así que el reparto es asimétrico a
        | propósito:
        |
        |   - ver    -> planta.ajustes.ver     (producción SÍ lo tiene: consulta
        |                                       lo que se ajustó en su bodega)
        |   - TODA escritura -> planta.ajustes.crear (admin-only en Fase 2)
        |
        | No se separan `confirmar` ni `reversar` en permisos propios porque hoy
        | las tres acciones recaen en la misma persona; el enum ya deja el ciclo
        | preparado para separarlas cuando exista un supervisor.
        |
        | `anular` es PATCH, no DELETE: no se borra nada, cambia el estado. NO hay
        | `destroy`.
        |
        | Rutas literales ANTES que las paramétricas, como el resto del archivo.
        */
        Route::middleware('permission:planta.ajustes.ver')->group(function () {
            $gestionar = 'permission:planta.ajustes.crear';

            Route::get('ajustes', [AjusteController::class, 'index'])->name('ajustes.index');
            Route::get('ajustes/crear', [AjusteController::class, 'create'])
                ->middleware($gestionar)->name('ajustes.create');
            Route::post('ajustes', [AjusteController::class, 'store'])
                ->middleware($gestionar)->name('ajustes.store');

            Route::get('ajustes/{ajuste}', [AjusteController::class, 'show'])->name('ajustes.show');
            Route::get('ajustes/{ajuste}/editar', [AjusteController::class, 'edit'])
                ->middleware($gestionar)->name('ajustes.edit');
            Route::put('ajustes/{ajuste}', [AjusteController::class, 'update'])
                ->middleware($gestionar)->name('ajustes.update');
            Route::patch('ajustes/{ajuste}/anular', [AjusteController::class, 'anular'])
                ->middleware($gestionar)->name('ajustes.anular');
            Route::patch('ajustes/{ajuste}/confirmar', [AjusteController::class, 'confirmar'])
                ->middleware($gestionar)->name('ajustes.confirmar');
            Route::patch('ajustes/{ajuste}/reversar', [AjusteController::class, 'reversar'])
                ->middleware($gestionar)->name('ajustes.reversar');
        });
    });
