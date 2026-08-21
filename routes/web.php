<?php

use App\Http\Controllers\Clientes\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Clientes\ClienteSucursalController;
use App\Http\Controllers\Facturacion\DteController;
use App\Http\Controllers\Productos\ProductoController;
use App\Http\Controllers\Productos\ProductoPrecioController;
use App\Http\Controllers\Usuarios\UserController;
use App\Http\Controllers\Auditoria\AuditoriaController;
use App\Http\Controllers\Configuracion\CorreoController;
use App\Http\Controllers\Configuracion\IntegracionDocumentosRecibidosController;
use App\Http\Controllers\Configuracion\IntegracionGmailController;
use App\Http\Controllers\Configuracion\SecretoController;
use App\Http\Controllers\Configuracion\CorrelativoController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\EstablecimientoController;
use App\Http\Controllers\Configuracion\FirmadorController;
use App\Http\Controllers\Configuracion\HaciendaController;
use App\Http\Controllers\Configuracion\InvalidacionController;
use App\Http\Controllers\Configuracion\ParametrosFiscalesController;
use App\Http\Controllers\Configuracion\PuntoVentaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Raíz: nunca la página de bienvenida de Breeze. Invitado -> login; autenticado
// -> dashboard. En el dominio público, el middleware CloudflareAccessSso ya corrió
// antes: con identidad Cloudflare válida y usuario local habilitado, acá se llega
// autenticado y se sigue directo al dashboard (sin doble login).
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

// Cierre de sesión COMPLETO (Laravel + Cloudflare Access): cierra la sesión local
// igual que el logout normal y redirige al endpoint de logout de Access del MISMO
// host, que invalida la cookie de Cloudflare. En hosts locales (sin Access) esa
// ruta no existe en el edge, por eso el enlace solo se muestra en el dominio
// público (ver layouts/navigation). El logout local normal queda intacto.
Route::post('/logout-completo', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/cdn-cgi/access/logout');
})->middleware('auth')->name('logout.completo');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified', 'area.principal'])->name('dashboard');

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

    // Auditoría general — permiso auditoria.ver (administrador y contabilidad). El
    // controlador refuerza el mismo permiso (defensa en profundidad).
    Route::middleware('permission:auditoria.ver')->group(function () {
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        // Listado ESCONDIDO de documentos de prueba/simulación (ambiente 00). No aparece en el
        // listado principal de facturación; se accede solo desde el panel de Auditoría.
        Route::get('auditoria/documentos-prueba', [AuditoriaController::class, 'documentosPrueba'])->name('auditoria.documentos_prueba');
    });

    /*
    | Facturación — borradores DTE. Por ahora solo CCF (tipo 03).
    | La autorización fina (gestión vs lectura, solo-borrador) la decide DtePolicy.
    */
    Route::prefix('facturacion')->name('facturacion.')->scopeBindings()->group(function () {
        Route::get('/', [DteController::class, 'index'])->name('index');
        // Invalidaciones: lista de documentos aceptados que se pueden invalidar (antes de {dte}).
        Route::get('invalidaciones', [DteController::class, 'invalidaciones'])->name('invalidaciones');
        Route::get('ccf/crear', [DteController::class, 'createCcf'])->name('create-ccf');
        Route::post('ccf', [DteController::class, 'storeCcf'])->name('store-ccf');
        Route::get('factura/crear', [DteController::class, 'createFactura'])->name('create-factura');
        Route::post('factura', [DteController::class, 'storeFactura'])->name('store-factura');
        Route::get('exportacion/crear', [DteController::class, 'createExportacion'])->name('create-exportacion');
        Route::post('exportacion', [DteController::class, 'storeExportacion'])->name('store-exportacion');

        // Nota de crédito como documento independiente (flujo propio, no desde un CCF).
        // Debe ir ANTES de `{dte}` para que «nota-credito/crear» no caiga en show.
        Route::get('nota-credito/crear', [DteController::class, 'createNotaCredito'])->name('create-nota-credito');
        // Autocomplete del CCF relacionado (JSON, solo lectura). Mismo permiso que el
        // formulario; también antes de `{dte}`.
        Route::get('nota-credito/buscar-ccf', [DteController::class, 'buscarCcfParaNotaCredito'])->name('nota-credito.buscar-ccf');
        Route::post('nota-credito', [DteController::class, 'storeNotaCreditoIndependiente'])->name('store-nota-credito');

        // Checklist "Preparar emisión real" — SOLO lectura/preparación (no emite, no
        // firma, no transmite, no mueve correlativos). Debe ir ANTES de `{dte}`.
        // Gestores ven el checklist; el backup solo-BD es admin-only.
        Route::get('preparar-produccion', [\App\Http\Controllers\Facturacion\PreparacionProduccionController::class, 'index'])
            ->middleware('permission:preparacion.ver')->name('preparar-produccion');
        Route::get('preparar-produccion/firmador', [\App\Http\Controllers\Facturacion\PreparacionProduccionController::class, 'firmador'])
            ->middleware('permission:preparacion.ver')->name('preparar-produccion.firmador');
        Route::post('preparar-produccion/backup', [\App\Http\Controllers\Facturacion\PreparacionProduccionController::class, 'backup'])
            ->middleware('permission:respaldos.ejecutar')->name('preparar-produccion.backup');

        // Reporte contadora (SOLO lectura + Excel; no emite, no transmite, no toca
        // correlativos). Debe ir ANTES de `{dte}`. Cualquier rol con reportes.ver.
        Route::get('reporte-contadora', [\App\Http\Controllers\Facturacion\ReporteContadoraController::class, 'index'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora');
        Route::get('reporte-contadora/exportar', [\App\Http\Controllers\Facturacion\ReporteContadoraController::class, 'exportar'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora.exportar');
        // JSON oficial ya generado, para el registro contable (solo lectura; NO lo genera).
        // Va por reportes.ver porque contabilidad no tiene dte.emitir (DtePolicy::verJson).
        Route::get('reporte-contadora/{dte}/json', [\App\Http\Controllers\Facturacion\ReporteContadoraController::class, 'descargarJson'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora.json');
        // Envío INDIVIDUAL de un documento aceptado a contabilidad (encolado, canal
        // contabilidad). Solo administrador y contabilidad; facturación NO. No emite,
        // no transmite, no toca correlativos ni el estado fiscal.
        Route::post('reporte-contadora/{dte}/enviar-contabilidad', [\App\Http\Controllers\Facturacion\ReporteContadoraController::class, 'enviarContabilidad'])
            ->middleware('permission:contabilidad.enviar')->name('reporte-contadora.enviar');

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
        // Acción REAL de producción, explícita y separada: preflight + generar (si borrador)
        // + firmar + transmitir. Exige barrera anti-Conta + frase EMITIR PRODUCCION. No envía correo.
        Route::post('{dte}/generar-transmitir-produccion', [DteController::class, 'generarTransmitirProduccion'])->name('generar-transmitir-produccion');
        // Invalidación (evento anulardte): SOLO mock + dry-run visual desde la UI. La
        // transmisión REAL a apitest se hace únicamente por consola (dte:invalidacion-real).
        // Acción sensible: SOLO administrador (permiso dte.invalidar). La DtePolicy la
        // refuerza además con las guardas de estado/evidencia.
        Route::post('{dte}/invalidacion/dry-run', [DteController::class, 'dryRunInvalidacion'])
            ->middleware('permission:dte.invalidar')->name('invalidacion.dry-run');
        Route::post('{dte}/invalidacion/mock', [DteController::class, 'invalidarMock'])
            ->middleware('permission:dte.invalidar')->name('invalidacion.mock');
        // Transmisión REAL del evento de invalidación a Hacienda (anulardte), reutilizando
        // DteInvalidacionService::transmitir(). Fuertemente candada: en el entorno actual
        // (modo seguro) los candados la BLOQUEAN. Exige la frase exacta INVALIDAR DTE
        // (validada en servidor por TransmitirInvalidacionRequest). Solo administrador.
        Route::post('{dte}/invalidacion/transmitir', [DteController::class, 'transmitirInvalidacion'])
            ->middleware('permission:dte.invalidar')->name('invalidacion.transmitir');
        Route::get('{dte}/editar', [DteController::class, 'edit'])->name('edit');
        // Datos aduaneros de una FEX (11) en borrador: recinto fiscal, régimen, incoterm, etc.
        Route::patch('{dte}/datos-aduaneros', [DteController::class, 'actualizarDatosAduaneros'])->name('datos-aduaneros.update');
        Route::post('{dte}/generar', [DteController::class, 'generar'])->name('generar');
        // Duplicar CCF: crea un borrador nuevo con los mismos datos base y líneas
        // (snapshot). No modifica el original ni copia numeración/firma/sello/correos.
        Route::post('{dte}/duplicar', [DteController::class, 'duplicar'])->name('duplicar');
        Route::post('{dte}/anular', [DteController::class, 'anular'])->name('anular');
        // Archivar / desarchivar un DTE RECHAZADO: lo retira de la operación diaria sin
        // borrarlo ni liberar el correlativo. Autorización fina en DtePolicy (solo
        // rechazados). No emite, no firma, no transmite.
        Route::post('{dte}/archivar', [DteController::class, 'archivar'])->name('archivar');
        Route::post('{dte}/desarchivar', [DteController::class, 'desarchivar'])->name('desarchivar');
        // Envío por correo al cliente (PDF + JSON/JWS), encolado. No transmite a Hacienda.
        Route::post('{dte}/correo', [DteController::class, 'enviarCorreo'])->name('correo.enviar');
        // Envío rápido de un clic al correo del cliente/sala (mismo pipeline encolado).
        Route::post('{dte}/correo/cliente', [DteController::class, 'enviarCorreoCliente'])->name('correo.cliente');
        Route::post('{dte}/correo/{envio}/reenviar', [DteController::class, 'reenviarCorreo'])->name('correo.reenviar');
        Route::delete('{dte}', [DteController::class, 'destroy'])->name('destroy');

        // Nota de crédito: crear desde un CCF original y acreditar líneas.
        Route::post('{dte}/nota-credito', [DteController::class, 'storeNotaCredito'])->name('nota-credito.store');
        // Reversión TOTAL: crea un borrador de NC por devolución con TODAS las líneas del
        // CCF (saldo acreditable disponible). No emite/firma/transmite ni toca el CCF.
        // Autorización fina en DtePolicy::revertirConNotaCredito (vía RevertirConNotaCreditoRequest).
        Route::post('{dte}/nota-credito/revertir', [DteController::class, 'revertirConNotaCredito'])->name('nota-credito.revertir');
        Route::post('{dte}/conceptos', [DteController::class, 'agregarConceptoNc'])->name('conceptos.store');
        // NC por avería: agregar producto libre del catálogo (no limitado al CCF original).
        Route::post('{dte}/averia', [DteController::class, 'agregarProductoAveria'])->name('averia.store');
        // {linea} es del documento ORIGINAL (otro dte), por eso no se escopa al {dte}.
        Route::post('{dte}/acreditar/{linea}', [DteController::class, 'acreditarLinea'])
            ->name('acreditar')
            ->withoutScopedBindings();

        // Fijar la cantidad de un producto en el borrador (auto-agregar/actualizar/quitar,
        // idempotente por producto). {producto} no es hijo de {dte}: sin scoped binding.
        Route::post('{dte}/productos/{producto}/cantidad', [DteController::class, 'setCantidadProducto'])
            ->name('productos.cantidad')
            ->withoutScopedBindings();
        // Modo escáner: agrega por código de barras exacto (o suma 1 si ya está en líneas).
        Route::post('{dte}/productos/escanear', [DteController::class, 'escanearProducto'])->name('productos.escanear');

        // Líneas del borrador.
        Route::post('{dte}/lineas', [DteController::class, 'storeLinea'])->name('lineas.store');
        // Línea SIN producto de catálogo (descripción libre): solo válida en FEX.
        Route::post('{dte}/lineas-libres', [DteController::class, 'storeLineaLibre'])->name('lineas-libres.store');
        Route::patch('{dte}/lineas/{linea}', [DteController::class, 'updateLinea'])->name('lineas.update');
        Route::delete('{dte}/lineas/{linea}', [DteController::class, 'destroyLinea'])->name('lineas.destroy');
    });

    /*
    | Documentos recibidos — CCF/facturas de proveedores que LLEGAN por correo.
    | Herramienta interna para preparar lo que se le manda a la contadora. SOLO
    | lectura/preparación: no reenvía, no envía correos, no modifica el buzón, no
    | borra, no toca DTE emitidos ni correlativos.
    */
    Route::prefix('documentos-recibidos')->name('documentos-recibidos.')->middleware('permission:documentos-recibidos.ver')->group(function () {
        Route::get('/', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'index'])->name('index');
        // Excel de recibidos respetando los filtros actuales (solo lectura, sin envío).
        Route::get('exportar', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'exportar'])->name('exportar');
        // Abre/descarga un adjunto YA guardado (pdf | json). Solo lectura de disco.
        Route::get('{documento}/archivo/{tipo}', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'descargarArchivo'])->name('archivo');

        // Envío INDIVIDUAL de la compra a contabilidad (encolado, con los adjuntos ya
        // guardados). Solo administrador y contabilidad (permiso contabilidad.enviar).
        // No lee el buzón Yahoo, no toca DTE emitidos ni correlativos.
        Route::post('{documento}/enviar-contabilidad', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'enviarContabilidad'])
            ->middleware('permission:contabilidad.enviar')->name('enviar-contabilidad');

        // Escritura: sincronización del buzón Yahoo/IMAP y cambios de estado interno.
        // SOLO administrador (permiso documentos-recibidos.gestionar).
        Route::middleware('permission:documentos-recibidos.gestionar')->group(function () {
            // Revisión MANUAL del buzón Yahoo/IMAP (solo lectura del buzón). No marca leído, no mueve, no borra.
            Route::post('sincronizar', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'sincronizar'])->name('sincronizar');
            Route::patch('{documento}/pendiente', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'marcarPendiente'])->name('pendiente');
            Route::patch('{documento}/ignorar', [\App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController::class, 'marcarIgnorado'])->name('ignorar');
            // Ya NO existe "marcar enviado" manual: una compra pasa a 'enviado' solo cuando
            // el envío individual por correo, o el paquete mensual, termina con éxito.
        });
    });

    /*
    | Contabilidad — herramienta INTERNA para preparar lo que se le manda a la
    | contadora (ella no entra al sistema). Paquete mensual = compras (recibidos) +
    | ventas (reporte contadora) en un ZIP. SOLO lectura: no envía correos, no toca
    | DTE emitidos, correlativos ni el buzón.
    */
    Route::prefix('contabilidad')->name('contabilidad.')->middleware('permission:reportes.ver')->group(function () {
        Route::get('paquete', [\App\Http\Controllers\Contabilidad\PaqueteContabilidadController::class, 'index'])->name('paquete');
        Route::post('paquete/generar', [\App\Http\Controllers\Contabilidad\PaqueteContabilidadController::class, 'generar'])->name('paquete.generar');
        // Envío MANUAL del paquete a contabilidad (requiere frase exacta). No cambia estados.
        // Solo administrador y contabilidad (permiso contabilidad.enviar); facturación NO.
        Route::post('paquete/enviar', [\App\Http\Controllers\Contabilidad\PaqueteContabilidadController::class, 'enviar'])
            ->middleware('permission:contabilidad.enviar')->name('paquete.enviar');
    });

    /*
    | Prontos Pagos (PPQ) — gestión de cobro de Calleja. Solo consulta CCF/NC ya
    | emitidos, los agrupa en lotes y (fase siguiente) genera el Excel. NO emite DTE.
    | Roles que gestionan cobros: administrador, contador, facturación.
    */
    Route::prefix('ppq')->name('ppq.')->middleware('permission:ppq.ver')->group(function () {
        Route::get('/', [\App\Http\Controllers\Ppq\PpqBusquedaController::class, 'index'])->name('index');
        // Búsqueda manual de albarán por fecha (cuando no se encontró por OC).
        Route::get('albaranes/por-fecha', [\App\Http\Controllers\Ppq\PpqBusquedaController::class, 'albaranesPorFecha'])->name('albaranes_por_fecha');
        // Lectura de lotes (índice, ficha) y su Excel: cualquier rol con ppq.ver.
        Route::get('lotes', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'index'])->name('lotes.index');
        // `crear` (escritura) DEBE declararse antes de `{lote}` para que la URL literal
        // no la capture la ruta de detalle.
        Route::get('lotes/crear', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'create'])
            ->middleware('permission:ppq.gestionar')->name('lotes.create');
        Route::get('lotes/{lote}', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'show'])->name('lotes.show');
        // Excel de Calleja desde un lote (phpspreadsheet).
        Route::get('lotes/{lote}/excel', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'excel'])->name('lotes.excel');

        // Escritura de PPQ (crear/editar/eliminar lotes e items, conciliar): solo
        // administrador y facturación (permiso ppq.gestionar). Jefatura/contabilidad
        // quedan en solo lectura.
        Route::middleware('permission:ppq.gestionar')->group(function () {
            Route::post('lotes', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'store'])->name('lotes.store');
            Route::get('lotes/{lote}/editar', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'edit'])->name('lotes.edit');
            Route::put('lotes/{lote}', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'update'])->name('lotes.update');
            Route::delete('lotes/{lote}', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'destroy'])->name('lotes.destroy');
            Route::post('lotes/{lote}/items', [\App\Http\Controllers\Ppq\PpqItemController::class, 'store'])->name('lotes.items.store');
            Route::delete('lotes/{lote}/items/{item}', [\App\Http\Controllers\Ppq\PpqItemController::class, 'destroy'])->name('lotes.items.destroy');
            // Conciliación del lote contra el TXT de pagos de Calleja.
            Route::post('lotes/{lote}/conciliar', [\App\Http\Controllers\Ppq\PpqLoteController::class, 'conciliar'])->name('lotes.conciliar');
        });

        // Conexión OAuth de Gmail (solo administrador). Nunca muestra tokens.
        Route::middleware('permission:ppq.gmail')->group(function () {
            Route::get('gmail/conectar', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'conectar'])->name('gmail.conectar');
            Route::get('gmail/callback', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'callback'])->name('gmail.callback');
            Route::delete('gmail', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'desconectar'])->name('gmail.desconectar');
            Route::get('gmail/debug', [\App\Http\Controllers\Ppq\PpqGmailController::class, 'debug'])->name('gmail.debug');
        });
    });

    /*
    | Exportaciones / Lista de Empaque — módulo administrativo paralelo: catálogo
    | de productos de exportación y generación del Excel desde la plantilla.
    | NO emite DTE, no toca correlativos, firma, transmisión ni correo.
    */
    // Lectura para cualquier rol con exportaciones.ver; la escritura lleva además
    // exportaciones.gestionar (inline en cada ruta que muta) para dejar a jefatura/
    // contabilidad en solo lectura sin alterar el orden literal-antes-de-parámetro.
    Route::prefix('exportaciones')->name('exportaciones.')->middleware('permission:exportaciones.ver')->group(function () {
        $gestionar = 'permission:exportaciones.gestionar';

        // Catálogo de productos de exportación (antes de {exportacion} para no chocar).
        Route::get('productos', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'index'])->name('productos.index');
        Route::get('productos/crear', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'create'])->middleware($gestionar)->name('productos.create');
        Route::post('productos', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'store'])->middleware($gestionar)->name('productos.store');
        // Importación del catálogo inicial desde el Excel plantilla (hoja "Lista").
        Route::get('productos/importar', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'importarForm'])->middleware($gestionar)->name('productos.importar');
        Route::post('productos/importar', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'importar'])->middleware($gestionar)->name('productos.importar.run');
        Route::get('productos/{producto}/editar', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'edit'])->middleware($gestionar)->name('productos.edit');
        Route::put('productos/{producto}', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'update'])->middleware($gestionar)->name('productos.update');
        Route::patch('productos/{producto}/toggle-activo', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'toggleActivo'])->middleware($gestionar)->name('productos.toggle-activo');
        Route::delete('productos/{producto}', [\App\Http\Controllers\Exportaciones\ExportacionProductoController::class, 'destroy'])->middleware($gestionar)->name('productos.destroy');

        // Clientes de exportación y su lista de precios/productos permitidos.
        Route::get('clientes', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'index'])->name('clientes.index');
        Route::get('clientes/crear', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'create'])->middleware($gestionar)->name('clientes.create');
        Route::post('clientes', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'store'])->middleware($gestionar)->name('clientes.store');
        Route::get('clientes/{cliente}', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'show'])->name('clientes.show');
        Route::get('clientes/{cliente}/editar', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'edit'])->middleware($gestionar)->name('clientes.edit');
        Route::put('clientes/{cliente}', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'update'])->middleware($gestionar)->name('clientes.update');
        Route::patch('clientes/{cliente}/toggle-activo', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'toggleActivo'])->middleware($gestionar)->name('clientes.toggle-activo');
        Route::delete('clientes/{cliente}', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'destroy'])->middleware($gestionar)->name('clientes.destroy');
        // Vínculo con el Cliente DTE real (solo guarda la relación; no crea clientes ni FEX).
        Route::patch('clientes/{cliente}/vincular-cliente-dte', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'vincularClienteDte'])->middleware($gestionar)->name('clientes.vincular-cliente-dte');
        Route::delete('clientes/{cliente}/vincular-cliente-dte', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'desvincularClienteDte'])->middleware($gestionar)->name('clientes.desvincular-cliente-dte');
        // Lista de precios del cliente (asignaciones producto+precio, únicas por cliente).
        Route::post('clientes/{cliente}/productos', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'storeProducto'])->middleware($gestionar)->name('clientes.productos.store');
        Route::post('clientes/{cliente}/productos/asignar-catalogo', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'asignarCatalogo'])->middleware($gestionar)->name('clientes.productos.asignar-catalogo');
        // Copiar productos/precios activos desde otro cliente (conservar u sobrescribir).
        Route::post('clientes/{cliente}/productos/copiar', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'copiarPrecios'])->middleware($gestionar)->name('clientes.productos.copiar');
        Route::patch('clientes/{cliente}/productos/{asignacion}', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'updateProducto'])->middleware($gestionar)->name('clientes.productos.update');
        Route::delete('clientes/{cliente}/productos/{asignacion}', [\App\Http\Controllers\Exportaciones\ExportacionClienteController::class, 'destroyProducto'])->middleware($gestionar)->name('clientes.productos.destroy');

        // Exportaciones / listas de empaque.
        Route::get('/', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'index'])->name('index');
        Route::get('crear', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'create'])->middleware($gestionar)->name('create');
        Route::post('/', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'store'])->middleware($gestionar)->name('store');
        Route::get('{exportacion}', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'show'])->name('show');
        Route::get('{exportacion}/editar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'edit'])->middleware($gestionar)->name('edit');
        Route::put('{exportacion}', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'update'])->middleware($gestionar)->name('update');
        // Excel de lista de empaque desde la plantilla oficial (phpspreadsheet, sin IA).
        Route::get('{exportacion}/excel', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'excel'])->name('excel');
        // Aprobación de la lista (revisada por la dueña). No emite nada.
        Route::patch('{exportacion}/aprobar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'aprobar'])->middleware($gestionar)->name('aprobar');
        Route::patch('{exportacion}/desaprobar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'desaprobar'])->middleware($gestionar)->name('desaprobar');
        // Archivar: oculta del listado normal una Lista de PRUEBA (no real) sin borrarla
        // ni tocar su FEX vinculada. Sigue accesible por URL directa o "Mostrar archivadas".
        Route::patch('{exportacion}/archivar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'archivar'])->middleware($gestionar)->name('archivar');
        Route::patch('{exportacion}/desarchivar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'desarchivar'])->middleware($gestionar)->name('desarchivar');
        // Crea (o abre, si ya existe) la FEX de esta Lista. Llama solo al servicio orquestador.
        Route::post('{exportacion}/crear-fex', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'crearFex'])->middleware($gestionar)->name('crear-fex');
        Route::post('{exportacion}/duplicar', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'duplicar'])->middleware($gestionar)->name('duplicar');
        Route::delete('{exportacion}', [\App\Http\Controllers\Exportaciones\ExportacionController::class, 'destroy'])->middleware($gestionar)->name('destroy');
    });
});

// Importación/exportación administrativa (CSV) — solo administrador.
Route::middleware(['auth', 'permission:importaciones.gestionar'])
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
Route::middleware(['auth', 'permission:sistema.salud'])
    ->get('admin/salud-sistema', [\App\Http\Controllers\Admin\SaludSistemaController::class, 'index'])
    ->name('admin.salud-sistema');

// Gestión de usuarios — solo administrador.
Route::middleware(['auth', 'permission:usuarios.gestionar'])->group(function () {
    Route::patch('usuarios/{usuario}/toggle-activo', [UserController::class, 'toggleActivo'])->name('usuarios.toggle-activo');
    Route::get('usuarios/{usuario}/password', [UserController::class, 'editPassword'])->name('usuarios.password.edit');
    Route::put('usuarios/{usuario}/password', [UserController::class, 'updatePassword'])->name('usuarios.password.update');
    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'usuario']);
});

/*
| Configuración del sistema — SOLO administrador.
| Empresa emisora, establecimientos, puntos de venta y correlativos.
*/
Route::middleware(['auth', 'permission:configuracion.gestionar'])
    ->prefix('configuracion')
    ->name('configuracion.')
    ->group(function () {
        // Resumen: estado de la configuración del sistema. SOLO LECTURA, sin
        // ninguna ruta de escritura asociada.
        Route::get('resumen', [\App\Http\Controllers\Configuracion\ResumenController::class, 'index'])->name('resumen');

        /*
        | FACTURACIÓN ELECTRÓNICA — SOLO LECTURA.
        |
        | Ninguna de estas rutas escribe configuración: no hay PUT, no hay DELETE
        | y no hay formularios de guardado. Lo que muestran se administra en el
        | archivo del servidor. Los dos POST son COMPROBACIONES, no cambios:
        |
        |   - `hacienda/probar` inicia sesión contra el ambiente de PRUEBAS del MH
        |     y no transmite ningún documento;
        |   - `firmador/probar` manda al firmador un documento inventado con NIT de
        |     relleno y contraseña falsa, y no firma ningún DTE real.
        |
        | Son POST y no GET porque tienen un efecto externo (una petición a otro
        | servicio, una línea en el historial de verificaciones), y eso no debe
        | poder dispararse recargando una página o precargando un enlace.
        */
        Route::get('facturacion-electronica/hacienda', [HaciendaController::class, 'index'])->name('fiscal.hacienda');
        Route::post('facturacion-electronica/hacienda/probar', [HaciendaController::class, 'probar'])->name('fiscal.hacienda.probar');

        Route::get('facturacion-electronica/firmador', [FirmadorController::class, 'index'])->name('fiscal.firmador');
        Route::post('facturacion-electronica/firmador/probar', [FirmadorController::class, 'probar'])->name('fiscal.firmador.probar');

        Route::get('facturacion-electronica/parametros', [ParametrosFiscalesController::class, 'index'])->name('fiscal.parametros');
        Route::get('facturacion-electronica/invalidacion', [InvalidacionController::class, 'index'])->name('fiscal.invalidacion');

        // Empresa emisora (registro único).
        Route::get('empresa', [EmpresaController::class, 'edit'])->name('empresa.edit');
        Route::put('empresa', [EmpresaController::class, 'update'])->name('empresa.update');

        // Correo: una pantalla con servidor SMTP, documentos fiscales y contabilidad.
        Route::get('correo', [CorreoController::class, 'edit'])->name('correo.edit');
        // Documentos fiscales (auto-envío, adjuntar JWS, plantilla). Nombre de ruta
        // intacto: es el que usaban la pantalla anterior y sus pruebas.
        Route::put('correo', [CorreoController::class, 'update'])->name('correo.update');

        // Servidor SMTP. Todos sus ajustes son N2: `updateSmtp` devuelve la pantalla
        // de confirmación cuando el envío no la trae.
        Route::put('correo/smtp', [CorreoController::class, 'updateSmtp'])->name('correo.smtp.update');
        Route::post('correo/smtp/probar', [CorreoController::class, 'probarConexion'])->name('correo.smtp.probar');

        // SECRETOS. Todos usan la MISMA pantalla; qué secreto administra cada ruta
        // se fija acá con ->defaults(), nunca en la petición. Es la diferencia
        // entre "cada secreto tiene su URL" y "hay una URL que escribe el secreto
        // que le mandes". `volver` es a dónde regresa el usuario al terminar.
        Route::get('correo/smtp/password', [SecretoController::class, 'edit'])
            ->name('correo.smtp.password.edit')
            ->defaults('clave', 'mail.smtp.password')->defaults('volver', 'configuracion.correo.edit');
        Route::put('correo/smtp/password', [SecretoController::class, 'update'])
            ->name('correo.smtp.password.update')
            ->defaults('clave', 'mail.smtp.password')->defaults('volver', 'configuracion.correo.edit');
        Route::delete('correo/smtp/password', [SecretoController::class, 'destroy'])
            ->name('correo.smtp.password.destroy')
            ->defaults('clave', 'mail.smtp.password')->defaults('volver', 'configuracion.correo.edit');

        /*
        | INTEGRACIONES — servicios externos que la aplicación consulta.
        |
        | Gmail (Prontos Pagos) y el buzón IMAP de compras. Cada uno con su
        | pantalla de estado + configuración, su prueba de conexión (que NO
        | sincroniza nada) y su secreto en pantalla aparte.
        */
        Route::get('integraciones/gmail', [IntegracionGmailController::class, 'index'])->name('integraciones.gmail');
        Route::put('integraciones/gmail', [IntegracionGmailController::class, 'update'])->name('integraciones.gmail.update');
        Route::post('integraciones/gmail/probar', [IntegracionGmailController::class, 'probar'])->name('integraciones.gmail.probar');
        Route::delete('integraciones/gmail/cuenta', [IntegracionGmailController::class, 'desconectar'])->name('integraciones.gmail.desconectar');
        Route::get('integraciones/gmail/secreto', [SecretoController::class, 'edit'])
            ->name('integraciones.gmail.secreto.edit')
            ->defaults('clave', 'ppq.gmail.client_secret')->defaults('volver', 'configuracion.integraciones.gmail');
        Route::put('integraciones/gmail/secreto', [SecretoController::class, 'update'])
            ->name('integraciones.gmail.secreto.update')
            ->defaults('clave', 'ppq.gmail.client_secret')->defaults('volver', 'configuracion.integraciones.gmail');
        Route::delete('integraciones/gmail/secreto', [SecretoController::class, 'destroy'])
            ->name('integraciones.gmail.secreto.destroy')
            ->defaults('clave', 'ppq.gmail.client_secret')->defaults('volver', 'configuracion.integraciones.gmail');

        Route::get('integraciones/documentos-recibidos', [IntegracionDocumentosRecibidosController::class, 'index'])->name('integraciones.documentos-recibidos');
        Route::put('integraciones/documentos-recibidos', [IntegracionDocumentosRecibidosController::class, 'update'])->name('integraciones.documentos-recibidos.update');
        Route::post('integraciones/documentos-recibidos/probar', [IntegracionDocumentosRecibidosController::class, 'probar'])->name('integraciones.documentos-recibidos.probar');
        Route::get('integraciones/documentos-recibidos/secreto', [SecretoController::class, 'edit'])
            ->name('integraciones.documentos-recibidos.secreto.edit')
            ->defaults('clave', 'documentos_recibidos.password')->defaults('volver', 'configuracion.integraciones.documentos-recibidos');
        Route::put('integraciones/documentos-recibidos/secreto', [SecretoController::class, 'update'])
            ->name('integraciones.documentos-recibidos.secreto.update')
            ->defaults('clave', 'documentos_recibidos.password')->defaults('volver', 'configuracion.integraciones.documentos-recibidos');
        Route::delete('integraciones/documentos-recibidos/secreto', [SecretoController::class, 'destroy'])
            ->name('integraciones.documentos-recibidos.secreto.destroy')
            ->defaults('clave', 'documentos_recibidos.password')->defaults('volver', 'configuracion.integraciones.documentos-recibidos');

        // Contabilidad: correo de contabilidad + copia (BCC) en el envío manual de DTE.
        // Guardar NO envía nada; la copia viaja dentro del envío existente.
        Route::get('contabilidad', [\App\Http\Controllers\Configuracion\ContabilidadController::class, 'edit'])->name('contabilidad.edit');
        Route::put('contabilidad', [\App\Http\Controllers\Configuracion\ContabilidadController::class, 'update'])->name('contabilidad.update');

        /*
        | SISTEMA — respaldos, cola, salud y entorno.
        |
        | Solo la política de respaldos es editable; el resto es diagnóstico que
        | reutiliza los servicios existentes. `respaldar` tiene su propio permiso,
        | más estrecho que el del grupo.
        */
        Route::get('sistema', [\App\Http\Controllers\Configuracion\SistemaController::class, 'index'])->name('sistema');
        Route::put('sistema', [\App\Http\Controllers\Configuracion\SistemaController::class, 'update'])->name('sistema.update');
        Route::post('sistema/respaldar', [\App\Http\Controllers\Configuracion\SistemaController::class, 'respaldar'])
            ->middleware('permission:respaldos.ejecutar')->name('sistema.respaldar');

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
require __DIR__.'/planta.php';
require __DIR__.'/rutas.php';
