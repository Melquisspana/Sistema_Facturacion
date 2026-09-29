<?php

use App\Http\Controllers\Gastos\AjusteController;
use App\Http\Controllers\Gastos\AvisoController;
use App\Http\Controllers\Gastos\CompraGastoController;
use App\Http\Controllers\Gastos\CompraRapidaController;
use App\Http\Controllers\Gastos\GastoController;
use App\Http\Controllers\Gastos\InformeGastoController;
use App\Http\Controllers\Gastos\PagoController;
use App\Http\Controllers\Gastos\PanelController;
use App\Http\Controllers\Gastos\ReglaController;
use Illuminate\Support\Facades\Route;

/*
| Gastos — qué hay que pagar, cuándo, cuánto se pagó y qué queda pendiente.
|
| Fase 1 completa: listado con pestañas y totales por ámbito, ficha de la
| obligación, alta con «Ya lo pagué», pagos posteriores y abonos sobre varias
| obligaciones del mismo destinatario, adjuntar después, reversión trazable,
| ajustes (notas de crédito/débito), vínculo opcional con Compras e informes.
|
| Fase 2: reglas recurrentes (semanal, quincenal, mensual, anual; monto fijo o
| variable), bandeja interna de avisos y resúmenes por correo.
|
| Qué NO hace, y no por descuido: no transmite DTE, no ejecuta pagos bancarios y no
| concilia con el banco. Un pago acá es una DECLARACIÓN del operador. Generar una
| obligación recurrente NUNCA la marca pagada. El correo de avisos no sale fuera de
| producción: queda registrado como simulado. Planilla, OCR y WhatsApp van después.
|
| ────────────────────────────── Los candados ──────────────────────────────
|
|  - `modulo.gastos` : 404 con GASTOS_ENABLED=false, 403 si el usuario está
|    desactivado. Interruptor del módulo mientras se construye.
|  - `permission:gastos.ver` : entrada al área.
|  - Los verbos finos se exigen POR ACCIÓN. Algunos no pueden vivir en la URL:
|    «este gasto es personal» y «ya lo pagué» son decisiones del CUERPO de la
|    petición, así que un mismo POST puede requerir tres permisos distintos según
|    lo que traiga marcado. Esos se comprueban en el FormRequest y en el servicio.
|
| El middleware NO se declara con una Closure. Una Closure dentro del array de
| middleware de un grupo revienta la resolución de rutas
| («Object of class Closure could not be converted to string»); por eso el
| interruptor vive en App\Http\Middleware\ModuloGastosActivo, como en Planta y
| Asistencia.
*/

Route::middleware(['auth', 'modulo.gastos', 'permission:gastos.ver'])
    ->prefix('gastos')
    ->name('gastos.')
    ->group(function () {
        $registrar = 'permission:gastos.registrar';
        $pagar = 'permission:gastos.pagos.registrar';
        $corregir = 'permission:gastos.pagos.corregir';
        $administrar = 'permission:gastos.administrar';

        /*
         | ── Las tres pantallas simples ──────────────────────────────────────
         |
         | Van ANTES de `{gasto}`, como informes y pagos: si no, «/gastos/cuentas»
         | entraría por el binding del modelo y devolvería 404.
         |
         | Son otra PUERTA a lo de siempre, no un atajo por fuera: terminan en los
         | mismos servicios, con los mismos permisos. «Compré y pagué» exige los dos
         | —registrar y pagar— porque hace las dos cosas en una operación.
         */
        Route::get('compre-y-pague', [CompraRapidaController::class, 'crearCompra'])
            ->middleware([$registrar, $pagar])->name('compre-y-pague');
        Route::post('compre-y-pague', [CompraRapidaController::class, 'guardarCompra'])
            ->middleware([$registrar, $pagar])->name('compre-y-pague.store');

        Route::get('cuentas', [CompraRapidaController::class, 'cuentas'])->name('cuentas');
        Route::get('cuentas/ver', [CompraRapidaController::class, 'cuenta'])->name('cuentas.show');
        Route::post('cuentas/compras', [CompraRapidaController::class, 'agregarCompra'])
            ->middleware($registrar)->name('cuentas.compras');
        Route::post('cuentas/abonos', [CompraRapidaController::class, 'abonar'])
            ->middleware($pagar)->name('cuentas.abonos');

        /*
         | ── La pantalla principal: LA PUERTA DEL ÁREA ───────────────────────
         |
         | Qué se debe, qué viene, qué falta completar y qué se pagó, en una sola
         | vista. Las cuatro acciones que salen de ella —pagar, abonar, compré y
         | pagué, anotar el monto— terminan en los MISMOS servicios que las
         | pantallas anteriores, con los mismos permisos.
         |
         | ── Por qué el panel se queda con `/gastos` y el listado se muda ──
         |
         | Entrar al área, tocar «Por pagar» o volver de registrar algo tienen que
         | llevar todos al mismo sitio, y ese sitio es este. Lo que se movió es la
         | URL, no los NOMBRES: `gastos.panel` y `gastos.index` siguen apuntando a
         | lo que apuntaban, así que ninguno de los enlaces ni de las pruebas que
         | los nombran cambia de significado. Cambiar el nombre en vez de la URL
         | habría obligado a reescribir cuarenta referencias para que dijeran lo
         | mismo.
         |
         | EL LISTADO CLÁSICO NO SE RETIRA. Vive en `/gastos/listado` y es el que
         | tiene la búsqueda, los filtros por ámbito, categoría, moneda y
         | responsable, y las seis pestañas. El panel resume; cuando hay que buscar
         | algo concreto, se va ahí. Sale del recorrido habitual, no del sistema.
         |
         | Todo lo demás cuelga de `panel/`, que es un segmento estático: así
         | ninguna de estas rutas puede chocar con el comodín `{gasto}` del final.
         */
        Route::get('/', [PanelController::class, 'index'])->name('panel');
        Route::post('panel/abonos', [PanelController::class, 'abonar'])
            ->middleware($pagar)->name('panel.abonos');
        // Las dos cosas en una transacción, así que los dos permisos.
        Route::post('panel/compras', [PanelController::class, 'compra'])
            ->middleware([$registrar, $pagar])->name('panel.compras');
        Route::post('panel/{gasto}/pagar', [PanelController::class, 'pagar'])
            ->whereNumber('gasto')->middleware($pagar)->name('panel.pagar');
        /*
         | Pagar una fila programada o de fecha pasada. Crea la obligación del período
         | —o reutiliza la que haya— y la paga en una confirmación.
         |
         | Exige los DOS permisos, y no es celo: materializar el período es crear una
         | obligación, que es `gastos.registrar`; pagarla es `gastos.pagos.registrar`.
         | Quien solo puede pagar no puede fabricar la deuda que va a pagar.
         |
         | Además `modulo.gastos.fase2`: sin el esquema de recurrencias no hay reglas
         | que materializar, y responde 503 explicado en vez de un error de SQL.
         */
        Route::post('panel/programado/{regla}/pagar', [PanelController::class, 'pagarProgramado'])
            ->whereNumber('regla')
            ->middleware([$registrar, $pagar, 'modulo.gastos.fase2'])
            ->name('panel.programado.pagar');
        Route::post('panel/{gasto}/completar', [PanelController::class, 'completarRecibo'])
            ->whereNumber('gasto')->middleware($registrar)->name('panel.completar');

        // ── Obligaciones ──────────────────────────────────────────────────────
        //
        // El listado con búsqueda, filtros y pestañas. Conserva su nombre de ruta
        // (`gastos.index`) y todo lo que hacía; lo único que cambió es que ya no
        // ocupa la raíz del área, que ahora es el panel.
        Route::get('listado', [GastoController::class, 'index'])->name('index');
        Route::get('crear', [GastoController::class, 'create'])->middleware($registrar)->name('create');
        Route::post('/', [GastoController::class, 'store'])->middleware($registrar)->name('store');

        // Informes ANTES de {gasto}: si no, /gastos/informes entraría por el binding
        // del modelo y devolvería 404 en vez de la pantalla.
        Route::get('informes', [InformeGastoController::class, 'index'])->name('informes');
        Route::get('informes/exportar', [InformeGastoController::class, 'exportar'])
            ->middleware('permission:gastos.exportar')->name('informes.exportar');

        // ── Pagos ─────────────────────────────────────────────────────────────
        // Historial de pagos: el dinero que salió. Responde otra pregunta que «Por
        // pagar», que habla de obligaciones. Alcanza con `gastos.ver`; lo que cada
        // quien VE dentro lo recorta AccesoGastos, no la ruta.
        Route::get('pagos', [PagoController::class, 'index'])->name('pagos.index');
        Route::get('pagos/crear', [PagoController::class, 'create'])->middleware($pagar)->name('pagos.create');
        Route::post('pagos', [PagoController::class, 'store'])->middleware($pagar)->name('pagos.store');
        Route::get('pagos/cuotas', [PagoController::class, 'cuotas'])->middleware($pagar)->name('pagos.cuotas');
        Route::get('pagos/{pago}', [PagoController::class, 'show'])->whereNumber('pago')->name('pagos.show');
        Route::post('pagos/{pago}/comprobantes', [PagoController::class, 'comprobantes'])
            ->whereNumber('pago')->middleware($pagar)->name('pagos.comprobantes');
        // Reversión: la ÚNICA forma de corregir un pago. Permiso propio y motivo obligatorio.
        Route::post('pagos/{pago}/revertir', [PagoController::class, 'revertir'])
            ->whereNumber('pago')->middleware($corregir)->name('pagos.revertir');

        // ── Ajustes de deuda (no son pagos) ───────────────────────────────────
        Route::post('cuotas/{cuota}/ajustes', [AjusteController::class, 'store'])
            ->whereNumber('cuota')->middleware($administrar)->name('ajustes.store');
        Route::post('ajustes/{ajuste}/revertir', [AjusteController::class, 'revertir'])
            ->whereNumber('ajuste')->middleware($administrar)->name('ajustes.revertir');

        // ── Vínculo opcional con Compras ──────────────────────────────────────
        Route::get('compras/{documento}', [CompraGastoController::class, 'elegir'])
            ->whereNumber('documento')->middleware($registrar)->name('compras.elegir');
        Route::post('compras/{documento}/vincular', [CompraGastoController::class, 'vincular'])
            ->whereNumber('documento')->middleware($registrar)->name('compras.vincular');

        // ── Archivos privados: SIEMPRE por controlador autorizado ─────────────
        Route::get('archivos/{adjunto}', [GastoController::class, 'archivo'])
            ->whereNumber('adjunto')->name('archivo');

        // ── Reglas recurrentes (fase 2) ───────────────────────────────────────
        //
        // VER una regla alcanza con `gastos.ver`: saber que el alquiler se repite no
        // es un dato sensible. CREARLA y TOCARLA exige `gastos.recurrencias`, que es
        // permiso propio: una regla no crea una deuda, crea una fábrica de deudas.
        //
        // Van ANTES de `{gasto}` por lo mismo que informes: si no, /gastos/reglas
        // entraría por el binding del modelo y daría 404.
        $recurrencias = 'permission:gastos.recurrencias';
        // TODAS las rutas de fase 2 exigen además que su esquema exista. Sin las
        // migraciones aplicadas responden 503 con la explicación, no un error de SQL.
        // Se aplica por grupo y no ruta por ruta: una ruta nueva que alguien agregue
        // acá dentro queda protegida sin tener que acordarse.
        Route::middleware('modulo.gastos.fase2')->group(function () use ($recurrencias) {

            Route::get('reglas', [ReglaController::class, 'index'])->name('reglas.index');
            Route::get('reglas/crear', [ReglaController::class, 'create'])->middleware($recurrencias)->name('reglas.create');
            Route::post('reglas', [ReglaController::class, 'store'])->middleware($recurrencias)->name('reglas.store');
            Route::get('reglas/{regla}', [ReglaController::class, 'show'])->whereNumber('regla')->name('reglas.show');
            Route::get('reglas/{regla}/editar', [ReglaController::class, 'edit'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.edit');
            Route::put('reglas/{regla}', [ReglaController::class, 'update'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.update');
            // Estado y períodos. Todos exigen motivo y quedan en la bitácora; ninguno
            // borra ni modifica una obligación ya generada.
            Route::post('reglas/{regla}/pausar', [ReglaController::class, 'pausar'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.pausar');
            Route::post('reglas/{regla}/reanudar', [ReglaController::class, 'reanudar'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.reanudar');
            // Completar la programación de una regla «Por completar» y activarla. Es
            // el único camino de borrador a activa, y exige el día que faltaba.
            Route::post('reglas/{regla}/activar', [ReglaController::class, 'activar'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.activar');
            Route::post('reglas/{regla}/cancelar', [ReglaController::class, 'cancelar'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.cancelar');
            Route::post('reglas/{regla}/omitir', [ReglaController::class, 'omitir'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.omitir');
            Route::post('reglas/{regla}/generar', [ReglaController::class, 'generar'])
                ->whereNumber('regla')->middleware($recurrencias)->name('reglas.generar');

            // ── Avisos: bandeja propia y preferencias propias ─────────────────────
            //
            // Sin permiso extra: son los avisos de quien está mirando. No hay ruta para
            // ver la bandeja de otra persona, y no es un olvido —un aviso puede nombrar
            // un gasto personal de quien lo recibió—.
            Route::get('avisos', [AvisoController::class, 'index'])->name('avisos.index');
            Route::post('avisos/leer-todos', [AvisoController::class, 'leerTodos'])->name('avisos.leer-todos');
            Route::get('avisos/preferencias', [AvisoController::class, 'preferencias'])->name('avisos.preferencias');
            Route::put('avisos/preferencias', [AvisoController::class, 'guardarPreferencias'])->name('avisos.preferencias.guardar');
            Route::post('avisos/{aviso}/leer', [AvisoController::class, 'leer'])->whereNumber('aviso')->name('avisos.leer');

        }); // fin del grupo que exige el esquema de fase 2

        // ── Ficha. Va al final: {gasto} es comodín y taparía las rutas de arriba ──
        Route::get('{gasto}', [GastoController::class, 'show'])->whereNumber('gasto')->name('show');
        Route::post('{gasto}/documentos', [GastoController::class, 'documentos'])
            ->whereNumber('gasto')->middleware($registrar)->name('documentos');
        // Poner el importe a una obligación que lo esperaba. COMPLETA la que existe;
        // no crea otra, que es lo que dejaría dos filas por la misma deuda.
        Route::post('{gasto}/completar', [GastoController::class, 'completar'])
            ->whereNumber('gasto')->middleware($registrar)->name('completar');
        // «Este gasto se repite» desde la ficha. Exige el permiso de repeticiones y
        // que su esquema exista; el gasto original y sus pagos no se tocan.
        // Pago rápido de un pendiente: el formulario corto, sin volver a pedir los
        // datos del gasto. Va después de las rutas estáticas pero es de las de {gasto}.
        Route::get('{gasto}/pagar', [CompraRapidaController::class, 'pagarRapido'])
            ->whereNumber('gasto')->middleware($pagar)->name('pagar-rapido');

        Route::post('{gasto}/repetir', [GastoController::class, 'repetir'])
            ->whereNumber('gasto')->middleware([$recurrencias, 'modulo.gastos.fase2'])->name('repetir');
    });
