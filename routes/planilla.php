<?php

use App\Http\Controllers\Planilla\PlanillaController;
use App\Http\Controllers\Planilla\PlanillaFlujoController;
use App\Http\Controllers\Planilla\PlanillaPagoController;
use Illuminate\Support\Facades\Route;

/*
| Planilla de control y recibos — fase 3, EN CONSTRUCCIÓN.
|
| Qué hay hasta acá: el registro de personas —reutilizando las identidades que ya
| existen en Asistencia, Rutas y usuarios—, la apertura de un período, el formulario
| de preparación y la vista previa de los formatos con datos ficticios.
|
| Y el flujo completo: confirmar (que es lo único que crea obligaciones), pagos
| individuales, por lote y parciales, anticipos con control de saldo, reversiones
| trazables, impresos reales y documentos firmados.
|
| ───────────────────────────── Los candados ─────────────────────────────
|
|  - `modulo.planilla` : 404 con PLANILLA_ENABLED=false; 403 si el usuario está
|    desactivado; 503 con explicación si faltan las migraciones del módulo.
|  - `permission:planilla.ver` : entrada al área. Deja ver QUÉ planillas existen y en
|    qué estado, SIN un solo importe.
|  - `planilla.salarios` : los importes. Es el permiso confidencial y se exige en el
|    controlador, no solo en la ruta, porque recorta pantallas que también se abren
|    con permisos menores.
|  - `planilla.gestionar` : preparar y confirmar. Confirmar crea obligaciones.
|
| ESTE MÓDULO NO CALCULA NADA LEGAL: ni ISSS, ni AFP, ni renta, ni vacaciones, ni
| aguinaldo, ni indemnización, ni horas extra. Control con importes revisados a mano.
*/

Route::middleware(['auth', 'modulo.planilla', 'permission:planilla.ver'])
    ->prefix('planilla')
    ->name('planilla.')
    ->group(function () {
        $gestionar = 'permission:planilla.gestionar';
        // Pagar exige TAMBIÉN ver los importes, y no es una precaución de más: para
        // declarar que salió dinero hay que saber cuánto era. Antes esto quedaba
        // implícito —`AccesoGastos` lo rechazaba al final del recorrido— y el usuario
        // recibía un 403 sin entender cuál de los dos permisos le faltaba.
        $pagar = ['permission:planilla.pagar', 'permission:planilla.salarios'];
        $documentos = 'permission:planilla.documentos';

        Route::get('/', [PlanillaController::class, 'index'])->name('index');

        // Formatos ANTES de {planilla}: si no, /planilla/formatos entraría por el
        // binding del modelo y daría 404. Mismo cuidado que en Gastos con informes.
        Route::get('formatos', [PlanillaController::class, 'formatos'])->name('formatos');

        // Registro de personas. Reutiliza identidades; no exige biometría.
        Route::get('empleados', [PlanillaController::class, 'empleados'])->name('empleados');
        Route::post('empleados', [PlanillaController::class, 'guardarEmpleado'])
            ->middleware($gestionar)->name('empleados.store');

        Route::get('abrir', [PlanillaController::class, 'create'])->middleware($gestionar)->name('create');
        Route::post('abrir', [PlanillaController::class, 'store'])->middleware($gestionar)->name('store');

        // Anticipos y documentos ANTES de {planilla}: si no, entrarían por el binding
        // del modelo y darían 404. Mismo cuidado que con `formatos`.
        Route::get('anticipos', [PlanillaPagoController::class, 'anticipos'])->name('anticipos');
        // El comprobante IMPRIME un adelanto que ya existe: no registra dinero, así que
        // basta el permiso de importes. Registrarlo sigue exigiendo `planilla.gestionar`.
        Route::get('anticipos/{anticipo}/comprobante', [PlanillaFlujoController::class, 'adelanto'])
            ->whereNumber('anticipo')->name('anticipos.comprobante');
        Route::post('empleados/{empleado}/anticipos', [PlanillaPagoController::class, 'guardarAnticipo'])
            ->whereNumber('empleado')->middleware($gestionar)->name('anticipos.store');

        // Los documentos firmados se entregan SOLO por controlador autorizado.
        Route::get('documentos/{documento}', [PlanillaFlujoController::class, 'verDocumento'])
            ->whereNumber('documento')->middleware($documentos)->name('documentos.ver');

        // Pagos. `planilla.pagar` es permiso propio: declarar que salió dinero no es lo
        // mismo que preparar la planilla.
        Route::post('detalles/{detalle}/pagar', [PlanillaPagoController::class, 'pagarPersona'])
            ->whereNumber('detalle')->middleware($pagar)->name('pagar.persona');
        Route::post('terceros/{obligacion}/pagar', [PlanillaPagoController::class, 'pagarTercero'])
            ->whereNumber('obligacion')->middleware($pagar)->name('pagar.tercero');
        // Revertir usa el permiso que YA existe en Gastos para eso. No se inventa uno
        // nuevo: deshacer un pago declarado es la misma autoridad, venga de donde venga.
        // Se le suman los laborales por la misma razón que a pagar: el importe que se
        // deshace es un sueldo.
        Route::post('pagos/{pago}/revertir', [PlanillaPagoController::class, 'revertirPago'])
            ->whereNumber('pago')
            ->middleware(['permission:gastos.pagos.corregir', 'permission:planilla.pagar', 'permission:planilla.salarios'])
            ->name('pagar.revertir');

        Route::get('{planilla}/preparar', [PlanillaController::class, 'preparar'])
            ->whereNumber('planilla')->middleware($gestionar)->name('preparar');
        Route::put('{planilla}/preparar', [PlanillaController::class, 'guardar'])
            ->whereNumber('planilla')->middleware($gestionar)->name('guardar');

        // ── El flujo de una planilla ya preparada ─────────────────────────
        Route::get('{planilla}', [PlanillaFlujoController::class, 'show'])->whereNumber('planilla')->name('show');
        Route::get('{planilla}/impresos', [PlanillaFlujoController::class, 'impresos'])->whereNumber('planilla')->name('impresos');
        Route::get('{planilla}/pagos', [PlanillaPagoController::class, 'pagos'])->whereNumber('planilla')->name('pagos');

        // Confirmar CREA OBLIGACIONES. Anular las extingue con un AJUSTE INTERNO —una
        // corrección de importe, nunca una nota de crédito fiscal: acá no se le emite
        // un documento tributario a nadie— y exige motivo. Las dos son de
        // `planilla.gestionar`.
        Route::post('{planilla}/confirmar', [PlanillaFlujoController::class, 'confirmar'])
            ->whereNumber('planilla')->middleware($gestionar)->name('confirmar');
        Route::post('{planilla}/anular', [PlanillaFlujoController::class, 'anular'])
            ->whereNumber('planilla')->middleware($gestionar)->name('anular');
        Route::post('{planilla}/lote', [PlanillaPagoController::class, 'pagarLote'])
            ->whereNumber('planilla')->middleware($pagar)->name('pagar.lote');
        Route::post('{planilla}/documentos', [PlanillaFlujoController::class, 'subirDocumento'])
            ->whereNumber('planilla')->middleware($documentos)->name('documentos.store');
    });
