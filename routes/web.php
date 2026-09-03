<?php

use App\Http\Controllers\Admin\ImportacionController;
use App\Http\Controllers\Admin\SaludSistemaController;
use App\Http\Controllers\Auditoria\AuditoriaController;
use App\Http\Controllers\Clientes\ClienteController;
use App\Http\Controllers\Clientes\ClienteExportacionController;
use App\Http\Controllers\Clientes\ClientePerfilDocumentoController;
use App\Http\Controllers\Clientes\ClienteSucursalController;
use App\Http\Controllers\Configuracion\ContabilidadController;
use App\Http\Controllers\Configuracion\CorrelativoController;
use App\Http\Controllers\Configuracion\CorreoController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\EstablecimientoController;
use App\Http\Controllers\Configuracion\FirmadorController;
use App\Http\Controllers\Configuracion\HaciendaController;
use App\Http\Controllers\Configuracion\IntegracionDocumentosRecibidosController;
use App\Http\Controllers\Configuracion\IntegracionGmailController;
use App\Http\Controllers\Configuracion\InvalidacionController;
use App\Http\Controllers\Configuracion\ParametrosFiscalesController;
use App\Http\Controllers\Configuracion\PuntoVentaController;
use App\Http\Controllers\Configuracion\ResumenController;
use App\Http\Controllers\Configuracion\SecretoController;
use App\Http\Controllers\Configuracion\SistemaController;
use App\Http\Controllers\Contabilidad\PaqueteContabilidadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentosRecibidos\DocumentoRecibidoController;
use App\Http\Controllers\Exportaciones\ExportacionProductoController;
use App\Http\Controllers\Facturacion\DteController;
use App\Http\Controllers\Facturacion\ListaEmpaqueController;
use App\Http\Controllers\Facturacion\PreparacionProduccionController;
use App\Http\Controllers\Facturacion\ReporteContadoraController;
use App\Http\Controllers\Ppq\NcExportacionController;
use App\Http\Controllers\Ppq\PpqBusquedaController;
use App\Http\Controllers\Ppq\PpqGmailController;
use App\Http\Controllers\Ppq\PpqItemController;
use App\Http\Controllers\Ppq\PpqLoteController;
use App\Http\Controllers\Productos\ProductoController;
use App\Http\Controllers\Productos\ProductoPrecioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Usuarios\UserController;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionProducto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
Route::post('/logout-completo', function (Request $request) {
    Auth::guard('web')->logout();
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

    // Perfil documental del cliente (albarán obligatorio en la NC, mapeo de modalidades a
    // los códigos del cliente y formato de exportación). Detrás de la MISMA autorización
    // que editar el cliente —ClientePolicy::update, es decir clientes.gestionar—, porque
    // decide el descuento de las notas de crédito igual que `descuento_global_default`.
    // Antes del resource para que la URL literal no la capture `clientes/{cliente}`.
    Route::get('clientes/{cliente}/perfil-documental', [ClientePerfilDocumentoController::class, 'edit'])
        ->name('clientes.perfil-documento.edit');
    Route::put('clientes/{cliente}/perfil-documental', [ClientePerfilDocumentoController::class, 'update'])
        ->name('clientes.perfil-documento.update');

    /*
    | Perfil de EXPORTACIÓN del cliente, dentro de su propia ficha.
    |
    | No hay un segundo directorio de clientes: estas rutas cuelgan del cliente que
    | ya existe y administran únicamente lo que el directorio no guarda —FDA del
    | importador, contacto del embarque, dirección de entrega y lista de precios—.
    |
    | Van ANTES del resource, o `clientes/{cliente}` capturaría el segmento literal.
    | Permiso: exportaciones.gestionar, el mismo que protegía estas acciones en la
    | pantalla anterior; lo refuerza además el propio controlador.
    */
    Route::prefix('clientes/{cliente}/exportacion')->name('clientes.exportacion.')
        ->middleware('permission:exportaciones.gestionar')
        ->group(function () {
            Route::post('habilitar', [ClienteExportacionController::class, 'habilitar'])->name('habilitar');
            Route::post('deshabilitar', [ClienteExportacionController::class, 'deshabilitar'])->name('deshabilitar');
            Route::put('/', [ClienteExportacionController::class, 'actualizar'])->name('update');

            Route::post('productos', [ClienteExportacionController::class, 'agregarProducto'])->name('productos.store');
            Route::post('productos/asignar-catalogo', [ClienteExportacionController::class, 'asignarCatalogo'])->name('productos.asignar-catalogo');
            Route::post('productos/copiar', [ClienteExportacionController::class, 'copiarPrecios'])->name('productos.copiar');
            Route::patch('productos/{asignacion}', [ClienteExportacionController::class, 'actualizarProducto'])->name('productos.update');
            Route::delete('productos/{asignacion}', [ClienteExportacionController::class, 'quitarProducto'])->name('productos.destroy');
        });

    Route::resource('clientes', ClienteController::class)->parameters(['clientes' => 'cliente']);

    // Productos. Autorización fina por ProductoPolicy.
    Route::patch('productos/{producto}/toggle-activo', [ProductoController::class, 'toggleActivo'])->name('productos.toggle-activo');

    // Precios por cliente/sucursal del producto (gestión = poder actualizar el producto).
    Route::post('productos/{producto}/precios', [ProductoPrecioController::class, 'store'])->name('productos.precios.store');
    Route::patch('productos/{producto}/precios/{precio}/toggle-activo', [ProductoPrecioController::class, 'toggleActivo'])->name('productos.precios.toggle-activo');
    Route::delete('productos/{producto}/precios/{precio}', [ProductoPrecioController::class, 'destroy'])->name('productos.precios.destroy');

    /*
    | Productos DE EXPORTACIÓN. Viven bajo la misma entrada «Productos» que los
    | nacionales, como segunda pestaña del selector, y por eso cuelgan de este
    | prefijo y no de /exportaciones.
    |
    | Van ANTES del resource: `productos/{producto}` capturaría `productos/exportacion`
    | y lo intentaría resolver como un Producto nacional inexistente (404).
    |
    | Los permisos NO cambian: siguen siendo exportaciones.ver para mirar y
    | exportaciones.gestionar para escribir, exactamente los mismos que protegían
    | estas pantallas en su ubicación anterior. Mover una pantalla de sitio no debe
    | cambiar quién puede entrar.
    |
    | Las rutas antiguas (exportaciones.productos.*) siguen vivas y sirviendo el
    | MISMO controlador: nadie que tenga un enlace guardado se queda sin destino.
    | Se retirarán cuando se compruebe en producción que no les queda ningún
    | consumidor.
    */
    Route::prefix('productos/exportacion')->name('productos.exportacion.')
        ->middleware('permission:exportaciones.ver')
        ->group(function () {
            $gestionar = 'permission:exportaciones.gestionar';

            Route::get('/', [ExportacionProductoController::class, 'index'])->name('index');
            Route::get('crear', [ExportacionProductoController::class, 'create'])->middleware($gestionar)->name('create');
            Route::post('/', [ExportacionProductoController::class, 'store'])->middleware($gestionar)->name('store');
            // Literales antes de {producto}, o «importar» se resolvería como un id.
            Route::get('importar', [ExportacionProductoController::class, 'importarForm'])->middleware($gestionar)->name('importar');
            Route::post('importar', [ExportacionProductoController::class, 'importar'])->middleware($gestionar)->name('importar.run');
            Route::get('{producto}', [ExportacionProductoController::class, 'show'])->name('show');
            Route::get('{producto}/editar', [ExportacionProductoController::class, 'edit'])->middleware($gestionar)->name('edit');
            Route::put('{producto}', [ExportacionProductoController::class, 'update'])->middleware($gestionar)->name('update');
            Route::patch('{producto}/toggle-activo', [ExportacionProductoController::class, 'toggleActivo'])->middleware($gestionar)->name('toggle-activo');
            Route::delete('{producto}', [ExportacionProductoController::class, 'destroy'])->middleware($gestionar)->name('destroy');
        });

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

        /*
        | Listas de empaque. Viven acá, dentro de Ventas y facturación, porque la
        | lista es el paso previo de la factura de exportación y se abre junto a
        | ella — no en un área aparte.
        |
        | Permisos: exportaciones.ver / exportaciones.gestionar, los MISMOS que
        | protegían estas pantallas en su ubicación anterior. Mover una pantalla no
        | cambia quién entra. La gestión se refuerza además en el controlador.
        |
        | Van antes de `{dte}` (declarado más abajo en este mismo grupo) para que el
        | segmento literal no se resuelva como el id de un documento.
        */
        Route::prefix('listas-empaque')->name('listas.')
            ->middleware('permission:exportaciones.ver')
            ->group(function () {
                $gestionar = 'permission:exportaciones.gestionar';

                Route::get('/', [ListaEmpaqueController::class, 'index'])->name('index');
                Route::get('crear', [ListaEmpaqueController::class, 'create'])->middleware($gestionar)->name('create');
                Route::post('/', [ListaEmpaqueController::class, 'store'])->middleware($gestionar)->name('store');
                Route::get('{lista}', [ListaEmpaqueController::class, 'show'])->name('show');
                Route::get('{lista}/editar', [ListaEmpaqueController::class, 'edit'])->middleware($gestionar)->name('edit');
                Route::put('{lista}', [ListaEmpaqueController::class, 'update'])->middleware($gestionar)->name('update');
                Route::delete('{lista}', [ListaEmpaqueController::class, 'destroy'])->middleware($gestionar)->name('destroy');
                Route::post('{lista}/duplicar', [ListaEmpaqueController::class, 'duplicar'])->middleware($gestionar)->name('duplicar');

                // Documentos. Lectura: los ve cualquiera con exportaciones.ver.
                Route::get('{lista}/excel', [ListaEmpaqueController::class, 'excel'])->name('excel');
                Route::get('{lista}/imprimir', [ListaEmpaqueController::class, 'imprimir'])->name('imprimir');

                // Facturación. «Facturar en el editor» NO crea nada: lleva al formulario
                // real de FEX con la lista en contexto.
                Route::get('{lista}/facturar', [ListaEmpaqueController::class, 'iniciarFactura'])->middleware($gestionar)->name('facturar');
                Route::post('{lista}/facturar-rapido', [ListaEmpaqueController::class, 'crearFexRapida'])->middleware($gestionar)->name('facturar-rapido');
                Route::post('{lista}/facturas', [ListaEmpaqueController::class, 'vincularFactura'])->middleware($gestionar)->name('facturas.vincular');
                Route::delete('{lista}/facturas/{dte}', [ListaEmpaqueController::class, 'desvincularFactura'])->middleware($gestionar)->name('facturas.desvincular');

                // Cierre y corrección auditada.
                Route::patch('{lista}/finalizar', [ListaEmpaqueController::class, 'finalizar'])->middleware($gestionar)->name('finalizar');
                Route::post('{lista}/reabrir', [ListaEmpaqueController::class, 'reabrir'])->middleware($gestionar)->name('reabrir');
                // Clasificación de una lista heredada del flujo anterior. Solo
                // administrador: el propio controlador lo refuerza.
                Route::post('{lista}/resolver-revision', [ListaEmpaqueController::class, 'resolverRevision'])->middleware($gestionar)->name('resolver-revision');
            });

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
        Route::get('preparar-produccion', [PreparacionProduccionController::class, 'index'])
            ->middleware('permission:preparacion.ver')->name('preparar-produccion');
        Route::get('preparar-produccion/firmador', [PreparacionProduccionController::class, 'firmador'])
            ->middleware('permission:preparacion.ver')->name('preparar-produccion.firmador');
        Route::post('preparar-produccion/backup', [PreparacionProduccionController::class, 'backup'])
            ->middleware('permission:respaldos.ejecutar')->name('preparar-produccion.backup');

        // Reporte contadora (SOLO lectura + Excel; no emite, no transmite, no toca
        // correlativos). Debe ir ANTES de `{dte}`. Cualquier rol con reportes.ver.
        Route::get('reporte-contadora', [ReporteContadoraController::class, 'index'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora');
        Route::get('reporte-contadora/exportar', [ReporteContadoraController::class, 'exportar'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora.exportar');
        // JSON oficial ya generado, para el registro contable (solo lectura; NO lo genera).
        // Va por reportes.ver porque contabilidad no tiene dte.emitir (DtePolicy::verJson).
        Route::get('reporte-contadora/{dte}/json', [ReporteContadoraController::class, 'descargarJson'])
            ->middleware('permission:reportes.ver')->name('reporte-contadora.json');
        // Envío INDIVIDUAL de un documento aceptado a contabilidad (encolado, canal
        // contabilidad). Solo administrador y contabilidad; facturación NO. No emite,
        // no transmite, no toca correlativos ni el estado fiscal.
        Route::post('reporte-contadora/{dte}/enviar-contabilidad', [ReporteContadoraController::class, 'enviarContabilidad'])
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
        // Autocomplete del documento de reemplazo (paso 2 del asistente, tipo 1 CAT-024).
        // SOLO CONSULTA (GET): no firma, no transmite, no toca BD. Mismo permiso y misma
        // ability que el resto del bloque; lo que devuelva se revalida al transmitir.
        Route::get('{dte}/invalidacion/buscar-reemplazo', [DteController::class, 'buscarReemplazoInvalidacion'])
            ->middleware('permission:dte.invalidar')->name('invalidacion.buscar-reemplazo');
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
        // Albarán de crédito que originó la NC (AC02/AC04 y equivalentes de otros clientes).
        // Solo captura datos del documento del cliente: no toca ningún valor fiscal.
        Route::post('{dte}/albaran', [DteController::class, 'guardarAlbaranNc'])->name('albaran.store');
        Route::delete('{dte}/albaran', [DteController::class, 'quitarAlbaranNc'])->name('albaran.destroy');
        // {linea} es del documento ORIGINAL (otro dte), por eso no se escopa al {dte}.
        //
        // `acreditar` SUMA a lo ya acreditado; `acreditar.cantidad` FIJA la cantidad (0 o
        // vacío quita la línea). La pantalla usa la segunda —es la que se comporta como el
        // catálogo del CCF y permite corregir sin borrar—, pero la primera sigue publicada
        // y funcionando: la usan pruebas y cualquier automatización previa, y sumar de a
        // poco sigue siendo una operación legítima.
        Route::post('{dte}/acreditar/{linea}', [DteController::class, 'acreditarLinea'])
            ->name('acreditar')
            ->withoutScopedBindings();
        Route::post('{dte}/acreditar/{linea}/cantidad', [DteController::class, 'setCantidadAcreditada'])
            ->name('acreditar.cantidad')
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
        Route::get('/', [DocumentoRecibidoController::class, 'index'])->name('index');
        // Excel de recibidos respetando los filtros actuales (solo lectura, sin envío).
        Route::get('exportar', [DocumentoRecibidoController::class, 'exportar'])->name('exportar');
        // Abre/descarga un adjunto YA guardado (pdf | json). Solo lectura de disco.
        Route::get('{documento}/archivo/{tipo}', [DocumentoRecibidoController::class, 'descargarArchivo'])->name('archivo');

        // Envío INDIVIDUAL de la compra a contabilidad (encolado, con los adjuntos ya
        // guardados). Solo administrador y contabilidad (permiso contabilidad.enviar).
        // No lee el buzón Yahoo, no toca DTE emitidos ni correlativos.
        Route::post('{documento}/enviar-contabilidad', [DocumentoRecibidoController::class, 'enviarContabilidad'])
            ->middleware('permission:contabilidad.enviar')->name('enviar-contabilidad');

        // Escritura: sincronización del buzón Yahoo/IMAP y cambios de estado interno.
        // SOLO administrador (permiso documentos-recibidos.gestionar).
        Route::middleware('permission:documentos-recibidos.gestionar')->group(function () {
            // Revisión MANUAL del buzón Yahoo/IMAP (solo lectura del buzón). No marca leído, no mueve, no borra.
            // Adelanta la corrida incremental que además hace el scheduler; no es la única defensa.
            Route::post('sincronizar', [DocumentoRecibidoController::class, 'sincronizar'])->name('sincronizar');
            // Recuperación EXCEPCIONAL de un período histórico, día por día y con progreso
            // persistente. Reemplaza al viejo "Revisar histórico", que releía siempre los
            // mismos correos recientes y nunca retrocedía.
            Route::post('recuperar', [DocumentoRecibidoController::class, 'recuperar'])->name('recuperar');
            Route::patch('{documento}/pendiente', [DocumentoRecibidoController::class, 'marcarPendiente'])->name('pendiente');
            Route::patch('{documento}/ignorar', [DocumentoRecibidoController::class, 'marcarIgnorado'])->name('ignorar');
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
        Route::get('paquete', [PaqueteContabilidadController::class, 'index'])->name('paquete');
        Route::post('paquete/generar', [PaqueteContabilidadController::class, 'generar'])->name('paquete.generar');
        // Envío MANUAL del paquete a contabilidad (requiere frase exacta). No cambia estados.
        // Solo administrador y contabilidad (permiso contabilidad.enviar); facturación NO.
        Route::post('paquete/enviar', [PaqueteContabilidadController::class, 'enviar'])
            ->middleware('permission:contabilidad.enviar')->name('paquete.enviar');
    });

    /*
    | Prontos Pagos (PPQ) — gestión de cobro de Calleja. Solo consulta CCF/NC ya
    | emitidos, los agrupa en lotes y (fase siguiente) genera el Excel. NO emite DTE.
    | Roles que gestionan cobros: administrador, contador, facturación.
    */
    Route::prefix('ppq')->name('ppq.')->middleware('permission:ppq.ver')->group(function () {
        Route::get('/', [PpqBusquedaController::class, 'index'])->name('index');
        // Búsqueda manual de albarán por fecha (cuando no se encontró por OC).
        Route::get('albaranes/por-fecha', [PpqBusquedaController::class, 'albaranesPorFecha'])->name('albaranes_por_fecha');
        // Lectura de lotes (índice, ficha) y su Excel: cualquier rol con ppq.ver.
        Route::get('lotes', [PpqLoteController::class, 'index'])->name('lotes.index');
        // `crear` (escritura) DEBE declararse antes de `{lote}` para que la URL literal
        // no la capture la ruta de detalle.
        Route::get('lotes/crear', [PpqLoteController::class, 'create'])
            ->middleware('permission:ppq.gestionar')->name('lotes.create');
        Route::get('lotes/{lote}', [PpqLoteController::class, 'show'])->name('lotes.show');
        // Excel de Calleja desde un lote (phpspreadsheet).
        Route::get('lotes/{lote}/excel', [PpqLoteController::class, 'excel'])->name('lotes.excel');

        // Escritura de PPQ (crear/editar/eliminar lotes e items, conciliar): solo
        // administrador y facturación (permiso ppq.gestionar). Jefatura/contabilidad
        // quedan en solo lectura.
        Route::middleware('permission:ppq.gestionar')->group(function () {
            Route::post('lotes', [PpqLoteController::class, 'store'])->name('lotes.store');
            Route::get('lotes/{lote}/editar', [PpqLoteController::class, 'edit'])->name('lotes.edit');
            Route::put('lotes/{lote}', [PpqLoteController::class, 'update'])->name('lotes.update');
            Route::delete('lotes/{lote}', [PpqLoteController::class, 'destroy'])->name('lotes.destroy');
            Route::post('lotes/{lote}/items', [PpqItemController::class, 'store'])->name('lotes.items.store');
            Route::delete('lotes/{lote}/items/{item}', [PpqItemController::class, 'destroy'])->name('lotes.items.destroy');
            // Conciliación del lote contra el TXT de pagos de Calleja.
            Route::post('lotes/{lote}/conciliar', [PpqLoteController::class, 'conciliar'])->name('lotes.conciliar');
        });

        /*
        | Deshacer un cobro ya registrado. Fuera del grupo de `ppq.gestionar` y con permiso
        | propio: aplicar lo que dice el archivo del cliente es la operación de todos los
        | días, contradecir un pago que ya se dio por cobrado no lo es.
        |
        | Va DESPUÉS del grupo anterior a propósito: `conciliar` conserva su permiso de
        | siempre, así que si este permiso todavía no se sembró (`db:seed --class=RolesSeeder`)
        | lo único que queda sin acceso es esta acción nueva, y la operación diaria sigue.
        */
        Route::post('lotes/{lote}/items/{item}/revertir-cobro', [PpqLoteController::class, 'revertirItem'])
            ->middleware('permission:ppq.revertir-conciliacion')
            ->name('lotes.items.revertir-cobro');

        // Envío diario de notas de crédito al cliente (una fila por NC). Consultar y
        // descargar basta con ppq.ver; crear el lote exige ppq.gestionar, porque marca
        // documentos como enviados.
        Route::get('nc-exportaciones', [NcExportacionController::class, 'index'])
            ->name('nc-exportaciones.index');
        Route::get('nc-exportaciones/{lote}/descargar', [NcExportacionController::class, 'descargar'])
            ->name('nc-exportaciones.descargar');
        Route::post('nc-exportaciones', [NcExportacionController::class, 'store'])
            ->middleware('permission:ppq.gestionar')->name('nc-exportaciones.store');

        // Conexión OAuth de Gmail (solo administrador). Nunca muestra tokens.
        Route::middleware('permission:ppq.gmail')->group(function () {
            Route::get('gmail/conectar', [PpqGmailController::class, 'conectar'])->name('gmail.conectar');
            Route::get('gmail/callback', [PpqGmailController::class, 'callback'])->name('gmail.callback');
            Route::delete('gmail', [PpqGmailController::class, 'desconectar'])->name('gmail.desconectar');
            Route::get('gmail/debug', [PpqGmailController::class, 'debug'])->name('gmail.debug');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Exportaciones — DESTINOS REUBICADOS (no eliminados)
    |--------------------------------------------------------------------------
    |
    | Las tres pantallas que vivían acá se mudaron a donde pertenecen:
    |
    |   catálogo de productos  →  productos.exportacion.*   (pestaña de Productos)
    |   clientes y precios     →  clientes.show             (ficha del cliente)
    |   listas de empaque      →  facturacion.listas.*      (Ventas y facturación)
    |
    | NINGUNA URL se borró: todas siguen resolviendo. Las de LECTURA redirigen a su
    | destino nuevo, así que un enlace guardado, un favorito o un correo viejo
    | llegan a donde deben en vez de a un 404.
    |
    | LAS DE ESCRITURA YA NO ESCRIBEN. Antes seguían apuntando a los controladores
    | originales «hasta comprobar producción», y eso dejaba una segunda forma de
    | escribir el mismo proceso saltándose las reglas nuevas: se podía editar una
    | lista finalizada con `PUT /exportaciones/{id}`, marcarla 'aprobada' con
    | `PATCH .../aprobar`, crearle una FEX sin validar receptor ni ambiente, o
    | borrar un producto de exportación con sus precios. Un candado que se evita
    | escribiendo otra URL no es un candado.
    |
    | Ahora responden 409 explicando a qué operación equivale cada una en el flujo
    | nuevo. Se conservan —no se borran— hasta comprobar en producción que nadie las
    | llama; lo que se retiró es su capacidad de mutar datos, no su existencia.
    |
    | `ExportacionController` y `ExportacionClienteController` quedan sin ninguna
    | ruta que los alcance. Siguen en el árbol a propósito: eliminarlos es el paso
    | siguiente, y exige la misma comprobación en producción.
    */
    Route::prefix('exportaciones')->name('exportaciones.')->middleware('permission:exportaciones.ver')->group(function () {
        $gestionar = 'permission:exportaciones.gestionar';

        /**
         * Respuesta de una ruta antigua de escritura: dice qué se hacía acá, dónde se
         * hace ahora y por qué ya no funciona. 409 Conflict —no 404— porque la URL
         * existe y el recurso también; lo que ya no es válido es la operación.
         */
        $retirada = function (string $equivalente) {
            return function () use ($equivalente) {
                abort(409, 'Esta dirección pertenece al flujo anterior de Exportaciones y ya no realiza cambios. '
                    .$equivalente.' Las reglas de revisión, finalización, vínculo con la factura y permisos '
                    .'viven ahora en un solo sitio, y mantener esta vía en paralelo permitía saltárselas.');
            };
        };

        // --- Catálogo de productos: ahora es la pestaña «De exportación» de Productos.
        Route::get('productos', fn () => redirect()->route('productos.exportacion.index'))->name('productos.index');
        Route::get('productos/crear', fn () => redirect()->route('productos.exportacion.create'))->middleware($gestionar)->name('productos.create');
        Route::get('productos/importar', fn () => redirect()->route('productos.exportacion.importar'))->middleware($gestionar)->name('productos.importar');
        Route::get('productos/{producto}/editar', fn (ExportacionProducto $producto) => redirect()->route('productos.exportacion.edit', $producto))->middleware($gestionar)->name('productos.edit');

        $aProductos = 'Usá Productos → pestaña «De exportación».';
        Route::post('productos', $retirada($aProductos))->middleware($gestionar)->name('productos.store');
        Route::post('productos/importar', $retirada($aProductos))->middleware($gestionar)->name('productos.importar.run');
        Route::put('productos/{producto}', $retirada($aProductos))->middleware($gestionar)->name('productos.update');
        Route::patch('productos/{producto}/toggle-activo', $retirada($aProductos))->middleware($gestionar)->name('productos.toggle-activo');
        Route::delete('productos/{producto}', $retirada('Un producto con precios o listas no se borra: se archiva desde su ficha en Productos → «De exportación».'))->middleware($gestionar)->name('productos.destroy');

        // --- Clientes y precios: ahora es el bloque «Exportación» de la ficha del cliente.
        // Sin cliente del directorio vinculado no hay ficha a la que llevar, así que se
        // cae al directorio filtrado por tipo exportación en vez de a un 404.
        Route::get('clientes', fn () => redirect()->route('clientes.index', ['tipo_cliente' => 'exportacion']))->name('clientes.index');
        Route::get('clientes/crear', fn () => redirect()->route('clientes.create'))->middleware($gestionar)->name('clientes.create');
        Route::get('clientes/{cliente}', fn (ExportacionCliente $cliente) => $cliente->cliente_id !== null
            ? redirect()->route('clientes.show', $cliente->cliente_id)
            : redirect()->route('clientes.index', ['tipo_cliente' => 'exportacion']))->name('clientes.show');
        Route::get('clientes/{cliente}/editar', fn (ExportacionCliente $cliente) => $cliente->cliente_id !== null
            ? redirect()->route('clientes.show', $cliente->cliente_id)
            : redirect()->route('clientes.index', ['tipo_cliente' => 'exportacion']))->middleware($gestionar)->name('clientes.edit');

        $aClientes = 'Usá la ficha del cliente, bloque «Exportación».';
        Route::post('clientes', $retirada($aClientes))->middleware($gestionar)->name('clientes.store');
        Route::put('clientes/{cliente}', $retirada($aClientes))->middleware($gestionar)->name('clientes.update');
        Route::patch('clientes/{cliente}/toggle-activo', $retirada($aClientes))->middleware($gestionar)->name('clientes.toggle-activo');
        Route::delete('clientes/{cliente}', $retirada($aClientes))->middleware($gestionar)->name('clientes.destroy');
        Route::patch('clientes/{cliente}/vincular-cliente-dte', $retirada($aClientes))->middleware($gestionar)->name('clientes.vincular-cliente-dte');
        Route::delete('clientes/{cliente}/vincular-cliente-dte', $retirada($aClientes))->middleware($gestionar)->name('clientes.desvincular-cliente-dte');
        Route::post('clientes/{cliente}/productos', $retirada($aClientes))->middleware($gestionar)->name('clientes.productos.store');
        Route::post('clientes/{cliente}/productos/asignar-catalogo', $retirada($aClientes))->middleware($gestionar)->name('clientes.productos.asignar-catalogo');
        Route::post('clientes/{cliente}/productos/copiar', $retirada($aClientes))->middleware($gestionar)->name('clientes.productos.copiar');
        Route::patch('clientes/{cliente}/productos/{asignacion}', $retirada($aClientes))->middleware($gestionar)->name('clientes.productos.update');
        Route::delete('clientes/{cliente}/productos/{asignacion}', $retirada($aClientes))->middleware($gestionar)->name('clientes.productos.destroy');

        // --- Listas de empaque: ahora viven en Ventas y facturación.
        Route::get('/', fn () => redirect()->route('facturacion.listas.index'))->name('index');
        Route::get('crear', fn () => redirect()->route('facturacion.listas.create'))->middleware($gestionar)->name('create');
        Route::get('{exportacion}', fn (Exportacion $exportacion) => redirect()->route('facturacion.listas.show', $exportacion))->name('show');
        Route::get('{exportacion}/editar', fn (Exportacion $exportacion) => redirect()->route('facturacion.listas.edit', $exportacion))->middleware($gestionar)->name('edit');
        Route::get('{exportacion}/excel', fn (Exportacion $exportacion) => redirect()->route('facturacion.listas.excel', $exportacion))->name('excel');

        $aListas = 'Usá Ventas y facturación → «Listas de empaque».';
        Route::post('/', $retirada($aListas))->middleware($gestionar)->name('store');
        Route::put('{exportacion}', $retirada($aListas))->middleware($gestionar)->name('update');
        // aprobar/desaprobar/archivar pertenecen al flujo anterior. El nuevo tiene dos
        // estados —borrador y finalizada— y una acción administrativa para clasificar
        // las listas heredadas; reabrir esta vía volvería a crear estados que nadie
        // sabe interpretar.
        Route::patch('{exportacion}/aprobar', $retirada('El flujo actual usa «Finalizar lista», que exige una factura vigente.'))->middleware($gestionar)->name('aprobar');
        Route::patch('{exportacion}/desaprobar', $retirada('El flujo actual usa «Reabrir lista», que exige un motivo y queda auditado.'))->middleware($gestionar)->name('desaprobar');
        Route::patch('{exportacion}/archivar', $retirada('Una lista heredada se clasifica —incluido «archivada»— con la acción administrativa de su ficha.'))->middleware($gestionar)->name('archivar');
        Route::patch('{exportacion}/desarchivar', $retirada($aListas))->middleware($gestionar)->name('desarchivar');
        Route::post('{exportacion}/crear-fex', $retirada('Facturá desde la ficha de la lista: ahí se validan receptor, ambiente y estado del documento.'))->middleware($gestionar)->name('crear-fex');
        Route::post('{exportacion}/duplicar', $retirada($aListas))->middleware($gestionar)->name('duplicar');
        Route::delete('{exportacion}', $retirada($aListas))->middleware($gestionar)->name('destroy');
    });
});

// Importación/exportación administrativa (CSV) — solo administrador.
Route::middleware(['auth', 'permission:importaciones.gestionar'])
    ->prefix('importaciones')
    ->name('importaciones.')
    ->group(function () {
        Route::get('/', [ImportacionController::class, 'index'])->name('index');
        Route::post('salas', [ImportacionController::class, 'importarSalas'])->name('salas.importar');
        Route::get('salas/exportar', [ImportacionController::class, 'exportarSalas'])->name('salas.exportar');
        Route::get('salas/plantilla', [ImportacionController::class, 'plantillaSalas'])->name('salas.plantilla');
        Route::post('precios', [ImportacionController::class, 'importarPrecios'])->name('precios.importar');
        Route::get('precios/exportar', [ImportacionController::class, 'exportarPrecios'])->name('precios.exportar');
        Route::get('precios/plantilla', [ImportacionController::class, 'plantillaPrecios'])->name('precios.plantilla');
    });

// Salud del sistema / Preparación para empresa — solo administrador (panel de solo lectura).
Route::middleware(['auth', 'permission:sistema.salud'])
    ->get('admin/salud-sistema', [SaludSistemaController::class, 'index'])
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
        Route::get('resumen', [ResumenController::class, 'index'])->name('resumen');

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
        Route::get('contabilidad', [ContabilidadController::class, 'edit'])->name('contabilidad.edit');
        Route::put('contabilidad', [ContabilidadController::class, 'update'])->name('contabilidad.update');

        /*
        | SISTEMA — respaldos, cola, salud y entorno.
        |
        | Solo la política de respaldos es editable; el resto es diagnóstico que
        | reutiliza los servicios existentes. `respaldar` tiene su propio permiso,
        | más estrecho que el del grupo.
        */
        Route::get('sistema', [SistemaController::class, 'index'])->name('sistema');
        Route::put('sistema', [SistemaController::class, 'update'])->name('sistema.update');
        Route::post('sistema/respaldar', [SistemaController::class, 'respaldar'])
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
require __DIR__.'/asistencia.php';
