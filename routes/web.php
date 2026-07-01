<?php

use App\Http\Controllers\Clientes\ClienteController;
use App\Http\Controllers\Clientes\ClienteSucursalController;
use App\Http\Controllers\Facturacion\DteController;
use App\Http\Controllers\Productos\ProductoController;
use App\Http\Controllers\Productos\ProductoPrecioController;
use App\Http\Controllers\Usuarios\UserController;
use App\Http\Controllers\Auditoria\AuditoriaController;
use App\Http\Controllers\Configuracion\CorreoController;
use App\Http\Controllers\Configuracion\CorrelativoController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\EstablecimientoController;
use App\Http\Controllers\Configuracion\PuntoVentaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Clientes. La autorización fina (gestión vs lectura) la decide ClientePolicy.
    Route::patch('clientes/{cliente}/toggle-activo', [ClienteController::class, 'toggleActivo'])->name('clientes.toggle-activo');

    // Sucursales / salas del cliente (gestión = poder actualizar el cliente).
    // La pertenencia sucursal↔cliente la verifica el controlador (abort 404).
    Route::get('clientes/{cliente}/sucursales/crear', [ClienteSucursalController::class, 'create'])->name('clientes.sucursales.create');
    Route::post('clientes/{cliente}/sucursales', [ClienteSucursalController::class, 'store'])->name('clientes.sucursales.store');
    Route::get('clientes/{cliente}/sucursales/{sucursal}/editar', [ClienteSucursalController::class, 'edit'])->name('clientes.sucursales.edit');
    Route::put('clientes/{cliente}/sucursales/{sucursal}', [ClienteSucursalController::class, 'update'])->name('clientes.sucursales.update');
    Route::patch('clientes/{cliente}/sucursales/{sucursal}/toggle-activo', [ClienteSucursalController::class, 'toggleActivo'])->name('clientes.sucursales.toggle-activo');
    Route::delete('clientes/{cliente}/sucursales/{sucursal}', [ClienteSucursalController::class, 'destroy'])->name('clientes.sucursales.destroy');

    Route::resource('clientes', ClienteController::class)->parameters(['clientes' => 'cliente']);

    // Productos. Autorización fina por ProductoPolicy.
    Route::patch('productos/{producto}/toggle-activo', [ProductoController::class, 'toggleActivo'])->name('productos.toggle-activo');

    // Precios por cliente/sucursal del producto (gestión = poder actualizar el producto).
    Route::post('productos/{producto}/precios', [ProductoPrecioController::class, 'store'])->name('productos.precios.store');
    Route::patch('productos/{producto}/precios/{precio}/toggle-activo', [ProductoPrecioController::class, 'toggleActivo'])->name('productos.precios.toggle-activo');
    Route::delete('productos/{producto}/precios/{precio}', [ProductoPrecioController::class, 'destroy'])->name('productos.precios.destroy');

    Route::resource('productos', ProductoController::class)->parameters(['productos' => 'producto']);

    // Auditoría general — solo administrador y contador (gate en el controlador).
    Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');

    /*
    | Facturación — borradores DTE. Por ahora solo CCF (tipo 03).
    | La autorización fina (gestión vs lectura, solo-borrador) la decide DtePolicy.
    */
    Route::prefix('facturacion')->name('facturacion.')->scopeBindings()->group(function () {
        Route::get('/', [DteController::class, 'index'])->name('index');
        Route::get('ccf/crear', [DteController::class, 'createCcf'])->name('create-ccf');
        Route::post('ccf', [DteController::class, 'storeCcf'])->name('store-ccf');
        Route::get('factura/crear', [DteController::class, 'createFactura'])->name('create-factura');
        Route::post('factura', [DteController::class, 'storeFactura'])->name('store-factura');
        Route::get('exportacion/crear', [DteController::class, 'createExportacion'])->name('create-exportacion');
        Route::post('exportacion', [DteController::class, 'storeExportacion'])->name('store-exportacion');

        // Nota de crédito como documento independiente (flujo propio, no desde un CCF).
        // Debe ir ANTES de `{dte}` para que «nota-credito/crear» no caiga en show.
        Route::get('nota-credito/crear', [DteController::class, 'createNotaCredito'])->name('create-nota-credito');
        Route::post('nota-credito', [DteController::class, 'storeNotaCreditoIndependiente'])->name('store-nota-credito');

        Route::get('{dte}', [DteController::class, 'show'])->name('show');
        Route::get('{dte}/imprimir', [DteController::class, 'imprimir'])->name('imprimir');
        // Representación gráfica PRELIMINAR en PDF (solo lectura; DtePolicy::view). NO transmite.
        Route::get('{dte}/pdf', [DteController::class, 'pdf'])->name('pdf');
        Route::get('{dte}/pdf/descargar', [DteController::class, 'descargarPdf'])->name('pdf.descargar');
        // Generar el JSON oficial preliminar desde la UI (DtePolicy::generarJson). No firma ni transmite.
        Route::post('{dte}/json/generar', [DteController::class, 'generarJson'])->name('json.generar');
        // Ver / descargar el JSON oficial preliminar ya generado (solo lectura; DtePolicy::verJson).
        Route::get('{dte}/json', [DteController::class, 'verJson'])->name('json');
        Route::get('{dte}/json/descargar', [DteController::class, 'descargarJson'])->name('json.descargar');
        // Ver / descargar el JWS firmado localmente (solo lectura; DtePolicy::verJsonFirmado).
        Route::get('{dte}/firmado', [DteController::class, 'verJsonFirmado'])->name('firmado');
        Route::get('{dte}/firmado/descargar', [DteController::class, 'descargarJsonFirmado'])->name('firmado.descargar');
        // Dry-run visual del estado técnico (solo diagnóstico; DtePolicy::verEstadoTecnico). NO transmite.
        Route::post('{dte}/dry-run', [DteController::class, 'dryRun'])->name('dry-run');
        // Acción MANUAL única: firma local + transmisión (DtePolicy::firmarTransmitir). Idempotente.
        // En modo MOCK (DTE_FIRMADOR_MOCK / MH_MOCK) simula firma y aceptación sin firmador ni red.
        Route::post('{dte}/firmar-transmitir', [DteController::class, 'firmarTransmitir'])->name('firmar-transmitir');
        // Invalidación (evento anulardte): SOLO mock + dry-run visual desde la UI. La
        // transmisión REAL a apitest se hace únicamente por consola (dte:invalidacion-real).
        Route::post('{dte}/invalidacion/dry-run', [DteController::class, 'dryRunInvalidacion'])->name('invalidacion.dry-run');
        Route::post('{dte}/invalidacion/mock', [DteController::class, 'invalidarMock'])->name('invalidacion.mock');
        Route::get('{dte}/editar', [DteController::class, 'edit'])->name('edit');
        Route::post('{dte}/generar', [DteController::class, 'generar'])->name('generar');
        Route::post('{dte}/anular', [DteController::class, 'anular'])->name('anular');
        // Envío por correo al cliente (PDF + JSON/JWS), encolado. No transmite a Hacienda.
        Route::post('{dte}/correo', [DteController::class, 'enviarCorreo'])->name('correo.enviar');
        Route::post('{dte}/correo/{envio}/reenviar', [DteController::class, 'reenviarCorreo'])->name('correo.reenviar');
        Route::delete('{dte}', [DteController::class, 'destroy'])->name('destroy');

        // Nota de crédito: crear desde un CCF original y acreditar líneas.
        Route::post('{dte}/nota-credito', [DteController::class, 'storeNotaCredito'])->name('nota-credito.store');
        Route::post('{dte}/conceptos', [DteController::class, 'agregarConceptoNc'])->name('conceptos.store');
        // NC por avería: agregar producto libre del catálogo (no limitado al CCF original).
        Route::post('{dte}/averia', [DteController::class, 'agregarProductoAveria'])->name('averia.store');
        // {linea} es del documento ORIGINAL (otro dte), por eso no se escopa al {dte}.
        Route::post('{dte}/acreditar/{linea}', [DteController::class, 'acreditarLinea'])
            ->name('acreditar')
            ->withoutScopedBindings();

        // Líneas del borrador.
        Route::post('{dte}/lineas', [DteController::class, 'storeLinea'])->name('lineas.store');
        Route::patch('{dte}/lineas/{linea}', [DteController::class, 'updateLinea'])->name('lineas.update');
        Route::delete('{dte}/lineas/{linea}', [DteController::class, 'destroyLinea'])->name('lineas.destroy');
    });

    /*
    | Prontos Pagos (PPQ) — gestión de cobro de Calleja. Solo consulta CCF/NC ya
    | emitidos, los agrupa en lotes y (fase siguiente) genera el Excel. NO emite DTE.
    | Roles que gestionan cobros: administrador, contador, facturación.
    */
    Route::prefix('ppq')->name('ppq.')->middleware('role:administrador|contador|facturacion')->group(function () {
        Route::get('/', [\App\Http\Controllers\Ppq\PpqBusquedaController::class, 'index'])->name('index');
        // Búsqueda manual de albarán por fecha (cuando no se encontró por OC).
        Route::get('albaranes/por-fecha', [\App\Http\Controllers\Ppq\PpqBusquedaController::class, 'albaranesPorFecha'])->name('albaranes_por_fecha');
        Route::resource('lotes', \App\Http\Controllers\Ppq\PpqLoteController::class)->parameters(['lotes' => 'lote']);
        Route::post('lotes/{lote}/items', [\App\Http\Controllers\Ppq\PpqItemController::class, 'store'])->name('lotes.items.store');
        Route::delete('lotes/{lote}/items/{item}', [\App\Http\Controllers\Ppq\PpqItemController::class, 'destroy'])->name('lotes.items.destroy');
        // Excel de Calleja desde un lote (phpspreadsheet).
        Route::get('lotes/{lote}/excel', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'excel'])->name('lotes.excel');
        // Conciliación del lote contra el TXT de pagos de Calleja (solo lectura).
        Route::post('lotes/{lote}/conciliar', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'conciliar'])->name('lotes.conciliar');

        // Conexión OAuth de Gmail (solo administrador). Nunca muestra tokens.
        Route::middleware('role:administrador')->group(function () {
            Route::get('gmail/conectar', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'conectar'])->name('gmail.conectar');
            Route::get('gmail/callback', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'callback'])->name('gmail.callback');
            Route::delete('gmail', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'desconectar'])->name('gmail.desconectar');
            Route::get('gmail/debug', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'debug'])->name('gmail.debug');
        });
    });
});

// Importación/exportación administrativa (CSV) — solo administrador.
Route::middleware(['auth', 'role:administrador'])
    ->prefix('importaciones')
    ->name('importaciones.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\ImportacionController::class, 'index'])->name('index');
        Route::post('salas', [\App\Http\Controllers\Admin\ImportacionController::class, 'importarSalas'])->name('salas.importar');
        Route::get('salas/exportar', [\App\Http\Controllers\Admin\ImportacionController::class, 'exportarSalas'])->name('salas.exportar');
        Route::get('salas/plantilla', [\App\Http\Controllers\Admin\ImportacionController::class, 'plantillaSalas'])->name('salas.plantilla');
        Route::post('precios', [\App\Http\Controllers\Admin\ImportacionController::class, 'importarPrecios'])->name('precios.importar');
        Route::get('precios/exportar', [\App\Http\Controllers\Admin\ImportacionController::class, 'exportarPrecios'])->name('precios.exportar');
        Route::get('precios/plantilla', [\App\Http\Controllers\Admin\ImportacionController::class, 'plantillaPrecios'])->name('precios.plantilla');
    });

// Salud del sistema / Preparación para empresa — solo administrador (panel de solo lectura).
Route::middleware(['auth', 'role:administrador'])
    ->get('admin/salud-sistema', [\App\Http\Controllers\Admin\SaludSistemaController::class, 'index'])
    ->name('admin.salud-sistema');

// Gestión de usuarios — solo administrador.
Route::middleware(['auth', 'role:administrador'])->group(function () {
    Route::patch('usuarios/{usuario}/toggle-activo', [UserController::class, 'toggleActivo'])->name('usuarios.toggle-activo');
    Route::get('usuarios/{usuario}/password', [UserController::class, 'editPassword'])->name('usuarios.password.edit');
    Route::put('usuarios/{usuario}/password', [UserController::class, 'updatePassword'])->name('usuarios.password.update');
    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'usuario']);
});

/*
| Configuración del sistema — SOLO administrador.
| Empresa emisora, establecimientos, puntos de venta y correlativos.
*/
Route::middleware(['auth', 'role:administrador'])
    ->prefix('configuracion')
    ->name('configuracion.')
    ->group(function () {
        // Empresa emisora (registro único).
        Route::get('empresa', [EmpresaController::class, 'edit'])->name('empresa.edit');
        Route::put('empresa', [EmpresaController::class, 'update'])->name('empresa.update');

        // Correo de DTE (auto-envío, adjuntar JWS, plantilla).
        Route::get('correo', [CorreoController::class, 'edit'])->name('correo.edit');
        Route::put('correo', [CorreoController::class, 'update'])->name('correo.update');

        // Establecimientos.
        Route::get('establecimientos', [EstablecimientoController::class, 'index'])->name('establecimientos.index');
        Route::get('establecimientos/crear', [EstablecimientoController::class, 'create'])->name('establecimientos.create');
        Route::post('establecimientos', [EstablecimientoController::class, 'store'])->name('establecimientos.store');
        Route::get('establecimientos/{establecimiento}/editar', [EstablecimientoController::class, 'edit'])->name('establecimientos.edit');
        Route::put('establecimientos/{establecimiento}', [EstablecimientoController::class, 'update'])->name('establecimientos.update');
        Route::delete('establecimientos/{establecimiento}', [EstablecimientoController::class, 'destroy'])->name('establecimientos.destroy');

        // Puntos de venta.
        Route::get('puntos-venta', [PuntoVentaController::class, 'index'])->name('puntos-venta.index');
        Route::get('puntos-venta/crear', [PuntoVentaController::class, 'create'])->name('puntos-venta.create');
        Route::post('puntos-venta', [PuntoVentaController::class, 'store'])->name('puntos-venta.store');
        Route::get('puntos-venta/{puntoVenta}/editar', [PuntoVentaController::class, 'edit'])->name('puntos-venta.edit');
        Route::put('puntos-venta/{puntoVenta}', [PuntoVentaController::class, 'update'])->name('puntos-venta.update');
        Route::delete('puntos-venta/{puntoVenta}', [PuntoVentaController::class, 'destroy'])->name('puntos-venta.destroy');

        // Correlativos.
        Route::get('correlativos', [CorrelativoController::class, 'index'])->name('correlativos.index');
        Route::get('correlativos/crear', [CorrelativoController::class, 'create'])->name('correlativos.create');
        Route::post('correlativos', [CorrelativoController::class, 'store'])->name('correlativos.store');
        Route::get('correlativos/{correlativo}/editar', [CorrelativoController::class, 'edit'])->name('correlativos.edit');
        Route::put('correlativos/{correlativo}', [CorrelativoController::class, 'update'])->name('correlativos.update');
        Route::delete('correlativos/{correlativo}', [CorrelativoController::class, 'destroy'])->name('correlativos.destroy');
    });

require __DIR__.'/auth.php';
