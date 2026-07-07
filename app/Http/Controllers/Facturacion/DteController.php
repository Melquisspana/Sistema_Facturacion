<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\CondicionPago;
use App\Enums\EstadoDte;
use App\Enums\TipoCliente;
use App\Enums\TipoDte;
use App\Enums\TipoNotaCredito;
use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Exceptions\Dte\DteJsonException;
use App\Exceptions\Dte\DteJsonInvalidoException;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Exceptions\Dte\GeneracionException;
use App\Exceptions\Dte\OrdenCompraRequeridaException;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dte\ActualizarLineaDteRequest;
use App\Http\Requests\Dte\AgregarLineaDteRequest;
use App\Http\Requests\Dte\CrearBorradorRequest;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteAnulacionService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteInvalidacionMockService;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\DteJsonService;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\PrecioProductoResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\DteEnvio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UI de Facturación — borradores CCF (tipo 03). Toda la lógica de cálculo y
 * persistencia vive en DteBorradorService; aquí solo se orquesta la pantalla.
 *
 * Alcance actual: solo CCF. Factura 01 y exportación 11 llegan en pasos posteriores.
 */
class DteController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly DteBorradorService $borradores) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Dte::class);

        $filtros = [
            'q' => trim((string) $request->input('q', '')),
            'tipo_dte' => $request->input('tipo_dte'),
            'estado' => $request->input('estado'),
            'cliente_id' => $request->input('cliente_id'),
            'cliente_sucursal_id' => $request->input('cliente_sucursal_id'),
            'fecha_desde' => $request->input('fecha_desde'),
            'fecha_hasta' => $request->input('fecha_hasta'),
        ];

        $dtes = Dte::query()
            ->select('dtes.*')
            // Listado principal = SOLO documentos reales de PRODUCCIÓN (ambiente 01), desde el
            // CCF 1078 en adelante. Las pruebas/piloto/simulación (ambiente 00) quedan fuera; su
            // acceso vive escondido en el panel de Auditoría. Sin toggle aquí a propósito.
            ->produccion()
            // Estado del ÚLTIMO envío de correo por documento (badge del listado), como
            // subquery para no caer en N+1. Solo lectura.
            ->addSelect(['ultimo_envio_estado' => DteEnvio::select('estado')
                ->whereColumn('dte_id', 'dtes.id')
                ->latest('id')
                ->limit(1)])
            ->with(['cliente', 'clienteSucursal', 'dteRelacionado.cliente', 'dteRelacionado.clienteSucursal'])
            ->when($filtros['tipo_dte'], fn ($qb, $v) => $qb->where('tipo_dte', $v))
            ->when($filtros['estado'], fn ($qb, $v) => $qb->where('estado', $v))
            ->when($filtros['cliente_id'], fn ($qb, $v) => $qb->where('cliente_id', $v))
            ->when($filtros['cliente_sucursal_id'], fn ($qb, $v) => $qb->where('cliente_sucursal_id', $v))
            ->when($filtros['fecha_desde'], fn ($qb, $v) => $qb->whereDate('fecha_emision', '>=', $v))
            ->when($filtros['fecha_hasta'], fn ($qb, $v) => $qb->whereDate('fecha_emision', '<=', $v))
            ->when($filtros['q'] !== '', function ($qb) use ($filtros) {
                $t = $filtros['q'];
                $qb->where(function ($w) use ($t) {
                    $w->where('numero_interno', 'like', "%{$t}%")
                        ->orWhere('numero_control', 'like', "%{$t}%")
                        ->orWhere('numero_orden_compra', 'like', "%{$t}%")
                        ->orWhere('motivo', 'like', "%{$t}%")
                        ->orWhere('observaciones', 'like', "%{$t}%")
                        ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$t}%")->orWhere('nombre_comercial', 'like', "%{$t}%"))
                        ->orWhereHas('clienteSucursal', fn ($s) => $s->where('nombre', 'like', "%{$t}%"))
                        ->orWhereHas('dteRelacionado', fn ($r) => $r->where('numero_interno', 'like', "%{$t}%")->orWhere('numero_control', 'like', "%{$t}%"));
                });
            })
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $clientes = Cliente::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        return view('facturacion.index', compact('dtes', 'filtros', 'clientes'));
    }

    public function createCcf(): View
    {
        $this->authorize('create', Dte::class);

        return view('facturacion.create-ccf', $this->datosFormularioCcf());
    }

    public function storeCcf(CrearBorradorRequest $request): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        $datos = $request->validated();
        $datos['tipo_dte'] = TipoDte::CreditoFiscal->value;

        // El correlativo NO se elige en el formulario: lo resuelve la generación.
        unset($datos['correlativo_id']);

        // La condición se TOMA de la sucursal/cliente (no se pide a mano). El
        // descuento es un PORCENTAJE del cliente/sucursal: el servicio lo resuelve
        // y lo convierte a monto al recalcular (no se pasa como monto aquí).
        $cliente = Cliente::find($datos['cliente_id'] ?? null);
        $sucursal = ClienteSucursal::find($datos['cliente_sucursal_id'] ?? null);
        unset($datos['descuento_global']);
        $datos['condicion_operacion'] = $this->condicionAplicada($cliente, $sucursal);

        try {
            $dte = $this->borradores->crearBorrador($datos, $request->user());
        } catch (OrdenCompraRequeridaException $e) {
            return back()->withInput()->withErrors(['numero_orden_compra' => $e->getMessage()]);
        }

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Borrador CCF creado. Agrega los productos.');
    }

    public function createFactura(): View
    {
        $this->authorize('create', Dte::class);

        return view('facturacion.create-factura', $this->datosFormularioFactura());
    }

    public function storeFactura(CrearBorradorRequest $request): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        $datos = $request->validated();
        $datos['tipo_dte'] = TipoDte::Factura->value;
        // Factura 01: cliente opcional, nunca orden de compra ni retención de IVA.
        $datos['aplica_retencion'] = false;
        unset($datos['numero_orden_compra'], $datos['correlativo_id']);

        // Condición desde cliente/sucursal; descuento PORCENTUAL resuelto por el servicio.
        $cliente = Cliente::find($datos['cliente_id'] ?? null);
        $sucursal = ClienteSucursal::find($datos['cliente_sucursal_id'] ?? null);
        unset($datos['descuento_global']);
        $datos['condicion_operacion'] = $this->condicionAplicada($cliente, $sucursal);

        $dte = $this->borradores->crearBorrador($datos, $request->user());

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Borrador de Factura creado. Agrega los productos.');
    }

    public function createExportacion(): View
    {
        $this->authorize('create', Dte::class);

        return view('facturacion.create-exportacion', $this->datosFormularioExportacion());
    }

    public function storeExportacion(CrearBorradorRequest $request): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        $datos = $request->validated();
        $datos['tipo_dte'] = TipoDte::FacturaExportacion->value;
        // Exportación: 0% IVA, sin retención ni orden de compra.
        $datos['aplica_retencion'] = false;
        unset($datos['numero_orden_compra'], $datos['correlativo_id']);

        // Condición desde cliente/sucursal; descuento PORCENTUAL resuelto por el servicio.
        // Flete y seguro SÍ vienen del request (propios de cada exportación).
        $cliente = Cliente::find($datos['cliente_id'] ?? null);
        $sucursal = ClienteSucursal::find($datos['cliente_sucursal_id'] ?? null);
        unset($datos['descuento_global']);
        $datos['condicion_operacion'] = $this->condicionAplicada($cliente, $sucursal);

        $dte = $this->borradores->crearBorrador($datos, $request->user());

        return redirect()
            ->route('facturacion.edit', $dte)
            ->with('status', 'Borrador de Factura de exportación creado. Agrega los productos.');
    }

    /**
     * Formulario de la Nota de crédito como documento INDEPENDIENTE.
     * Si llega ?ccf={id} y es un CCF generado, se preselecciona como relacionado.
     */
    public function createNotaCredito(Request $request): View
    {
        $this->authorize('create', Dte::class);

        $preCcf = null;
        if ($request->filled('ccf')) {
            // La NC solo se crea desde un CCF ACEPTADO REALMENTE por Hacienda (no mock/local).
            $preCcf = Dte::query()
                ->where('tipo_dte', TipoDte::CreditoFiscal->value)
                ->aceptadoRealMh()
                ->with('cliente')
                ->find($request->integer('ccf'));
        }

        return view('facturacion.create-nota-credito', array_merge(
            $this->datosFormularioNotaCredito(),
            ['preCcf' => $preCcf],
        ));
    }

    /**
     * Crea una Nota de crédito desde su flujo propio (no desde un CCF).
     * El CCF relacionado es OPCIONAL aquí (salvo NC por productos, que lo exige
     * el servicio). El cliente se toma del original si se vincula uno.
     */
    public function storeNotaCreditoIndependiente(Request $request): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        $datos = $request->validate([
            'tipo' => ['required', \Illuminate\Validation\Rule::in(array_map(fn ($t) => $t->value, TipoNotaCredito::cases()))],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'cliente_sucursal_id' => ['nullable', 'integer', 'exists:cliente_sucursales,id'],
            'dte_relacionado_id' => ['nullable', 'integer', 'exists:dtes,id'],
            'establecimiento_id' => ['required', 'integer', 'exists:establecimientos,id'],
            'punto_venta_id' => ['required', 'integer', 'exists:puntos_venta,id'],
            'motivo' => ['nullable', 'string', 'max:1000'],
            // numero_orden_compra NO se acepta: se copia del CCF relacionado.
        ]);

        $original = ! empty($datos['dte_relacionado_id'])
            ? Dte::find($datos['dte_relacionado_id'])
            : null;

        // Sin CCF relacionado, el cliente es obligatorio (la NC necesita receptor).
        if ($original === null && empty($datos['cliente_id'])) {
            return back()->withInput()->withErrors([
                'cliente_id' => 'Seleccione un cliente o un CCF relacionado para la nota de crédito.',
            ]);
        }

        // crearNotaCredito valida coherencia (CCF emitido, cliente del original,
        // y exige relacionado en NC por productos) y lanza ValidationException.
        $nc = $this->borradores->crearNotaCredito($original, $datos, $request->user());

        return redirect()->route('facturacion.edit', $nc)->with('status', $this->mensajeNotaCredito($nc));
    }

    public function show(Dte $dte, DteTransmisionService $transmision, DteInvalidacionService $invalidacionService): View
    {
        $this->authorize('view', $dte);

        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento', 'puntoVenta', 'dteRelacionado', 'anuladoPor', 'envios']);

        // Solo para precisar la nota de retención en los totales (no recalcula nada).
        $esAgenteRetencion = $this->borradores->esAgenteRetencion($dte);

        // Estado técnico / preflight (solo gestores). Reusa DteTransmisionService:
        // diagnóstico de SOLO LECTURA, no transmite, no toca BD, no muestra secretos.
        $tecnico = null;
        if (auth()->user()?->can('verEstadoTecnico', $dte)) {
            $candados = $transmision->evaluarCandados();
            $preflight = $transmision->preflight($dte);
            $tecnico = [
                'candados' => $candados,
                'preflight' => $preflight,
                'resultado' => $preflight['listo'] ? 'LISTO PARA TRANSMISIÓN' : 'BLOQUEADO',
                'dry_run_disponible' => $transmision->puedeDryRun($dte),
                'dry_run' => session('dry_run'),
            ];
        }

        // Aviso operativo SOLO para gestores: correos en cola sin procesar hace >5 min
        // (worker probablemente apagado). Lectura simple de la tabla jobs; no toca la cola.
        $correosAtascados = 0;
        if (auth()->user()?->can('verEstadoTecnico', $dte)) {
            $correosAtascados = (int) DB::table('jobs')
                ->where('created_at', '<=', now()->subMinutes(5)->getTimestamp())
                ->count();
        }

        // Invalidación (evento anulardte): panel de candados + evidencia. SOLO LECTURA aquí;
        // evaluarCandados no firma, no transmite, no toca BD. La UI solo ofrece mock/dry-run.
        $invalidacion = null;
        if (auth()->user()?->can('verInvalidacion', $dte)) {
            $evento = $this->eventoInvalidacionDesdeConfig(TipoAnulacionMh::RescindirOperacion);
            $invalidacion = [
                'puede_mock' => auth()->user()->can('invalidarMock', $dte),
                'mock_activo' => (bool) config('dte.invalidacion.mock', false),
                'ya_invalidado' => $dte->tieneEventoInvalidacion(),
                'candados' => $invalidacionService->evaluarCandados($dte, $evento, false, false),
                'dry_run' => session('dry_run_invalidacion'),
                'tipos' => TipoAnulacionMh::opciones(),
            ];
        }

        return view('facturacion.show', compact('dte', 'esAgenteRetencion', 'tecnico', 'invalidacion', 'correosAtascados'));
    }

    /**
     * Vista imprimible / PDF preliminar INTERNO. Todos los lectores pueden verla.
     * No es un DTE válido ante Hacienda (aún sin JSON/firma/sello).
     */
    public function imprimir(Dte $dte): View
    {
        $this->authorize('view', $dte);

        $dte->load([
            'cliente.departamento', 'cliente.municipio', 'cliente.actividadEconomica',
            'clienteSucursal.departamento', 'clienteSucursal.municipio', 'clienteSucursal.distrito.departamento',
            'lineas',
            'establecimiento.empresa.departamento', 'establecimiento.empresa.municipio',
            'establecimiento.departamento', 'establecimiento.municipio',
            'puntoVenta', 'dteRelacionado',
        ]);

        // Mismo criterio que el PDF: usa la empresa REAL (no el emisor placeholder).
        $emisor = $this->resolverEmisorParaPdf($dte);
        $logoSrc = $this->logoSrcPdf();

        return view('facturacion.imprimir', compact('dte', 'emisor', 'logoSrc'));
    }

    /**
     * Representación gráfica PRELIMINAR en PDF (dompdf). Solo lectura: NO transmite,
     * NO cambia estado, NO guarda sello, NO usa credenciales. Si no hay sello de
     * recepción, el PDF se marca como preliminar / no transmitido. `view` (lectores).
     */
    public function pdf(Dte $dte): Response
    {
        $this->authorize('view', $dte);

        return $this->construirPdf($dte)->stream($this->nombrePdf($dte));
    }

    /** Descarga del PDF preliminar (mismas garantías que pdf()). */
    public function descargarPdf(Dte $dte): Response
    {
        $this->authorize('view', $dte);

        return $this->construirPdf($dte)->download($this->nombrePdf($dte));
    }

    /** Construye el PDF (delegado a DtePdfService, reutilizado por el Job de correo). */
    private function construirPdf(Dte $dte): \Barryvdh\DomPDF\PDF
    {
        return app(\App\Services\Dte\DtePdfService::class)->pdf($dte);
    }

    /** Emisor a mostrar en el PDF/impresión (empresa real si el enlazado es placeholder). */
    public function resolverEmisorParaPdf(Dte $dte): ?Empresa
    {
        return app(\App\Services\Dte\DtePdfService::class)->emisor($dte);
    }

    /** Logo del emisor como data-URI (delegado). */
    private function logoSrcPdf(): ?string
    {
        return app(\App\Services\Dte\DtePdfService::class)->logoSrc();
    }

    /** Nombre del archivo PDF (delegado). */
    private function nombrePdf(Dte $dte): string
    {
        return app(\App\Services\Dte\DtePdfService::class)->nombre($dte);
    }

    /**
     * Ver (inline) el JSON oficial PRELIMINAR ya generado. Solo lectura del archivo
     * apuntado por json_generado_path; NO regenera, NO toca BD, NO firma, NO transmite.
     */
    public function verJson(Dte $dte): Response
    {
        $this->authorize('verJson', $dte); // gestor + json_generado_path presente (DtePolicy)

        [$disco, $ruta] = $this->rutaJsonSegura($dte);

        $contenido = Storage::disk($disco)->get($ruta);

        return response($contenido, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Descargar el JSON oficial PRELIMINAR ya generado. Solo lectura; mismas garantías
     * que verJson (no regenera, no toca BD, no firma, no transmite).
     */
    public function descargarJson(Dte $dte): StreamedResponse
    {
        $this->authorize('verJson', $dte);

        [$disco, $ruta] = $this->rutaJsonSegura($dte);

        return Storage::disk($disco)->download($ruta, basename($ruta), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /**
     * Generar el JSON oficial PRELIMINAR desde la UI usando el mismo DteJsonService
     * que el comando `dte:generar-json`: asigna numeración, serializa, valida contra
     * el schema y guarda el archivo + json_generado_path (todo en una transacción).
     * NO firma, NO transmite, NO guarda sello, NO cambia estado a aceptado.
     */
    public function generarJson(Dte $dte, DteJsonService $jsonService): RedirectResponse
    {
        // gestor + estado generado + sin JSON previo (no se regenera desde la UI).
        $this->authorize('generarJson', $dte);

        try {
            $jsonService->generar($dte); // force=false: nunca regenera desde la UI
        } catch (DteJsonInvalidoException $e) {
            return redirect()
                ->route('facturacion.show', $dte)
                ->with('error', 'El JSON no pasó la validación contra el schema oficial (no se guardó nada): '
                    .implode(' | ', array_slice($e->errores, 0, 8)));
        } catch (DteJsonException $e) {
            return redirect()
                ->route('facturacion.show', $dte)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('facturacion.show', $dte)
            ->with('status', 'JSON generado localmente. SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA.');
    }

    /**
     * Acción MANUAL única: firma y transmite el DTE en un solo paso, de forma idempotente.
     * Reusa los servicios existentes (DteJsonService/DteFirmaService/DteTransmisionService) y
     * su máquina de estados; NO duplica lógica de firma/transmisión. En modo MOCK
     * (DTE_FIRMADOR_MOCK / MH_MOCK) simula firma y aceptación sin firmador ni red.
     *
     * Garantías: NO consume correlativo nuevo (lo asigna la generación de JSON), NO re-firma si
     * ya hay JWS, NO retransmite si ya hay sello / está aceptado, y ante cualquier fallo deja el
     * documento en un estado consistente (generado o firmado) para reintentar. NO toca correos
     * ni PPQ.
     */
    public function firmarTransmitir(
        Request $request,
        Dte $dte,
        DteJsonService $jsonService,
        DteFirmaService $firma,
        DteTransmisionService $transmision,
    ): RedirectResponse {
        $this->authorize('firmarTransmitir', $dte);

        $volver = redirect()->route('facturacion.show', $dte);

        // 0. Idempotencia dura: si ya está aceptado o ya tiene sello, no se hace nada.
        if ($dte->estado === EstadoDte::Aceptado || filled($dte->sello_recepcion)) {
            return $volver->with('status', 'El documento ya fue aceptado; no se vuelve a firmar ni transmitir.');
        }

        // 0.1 GUARDIA DE EMISIÓN REAL A PRODUCCIÓN: si los candados permiten transmitir de
        // verdad a producción, exigir la frase EXACTA escrita a mano. En MODO SEGURO
        // (paralelo/mock/dry-run/apitest) esto NO aplica (emisionRealPosible=false) y el
        // flujo sigue con su doble confirmación normal, sin estorbar. Antes de firmar/transmitir.
        if ($transmision->emisionRealPosible()
            && trim((string) $request->input('confirmacion_emision', '')) !== 'EMITIR PRODUCCION') {
            Log::warning('DTE firmar-transmitir: emisión real bloqueada por falta de frase', ['dte_id' => $dte->id]);

            return $volver->with('error', 'Emisión a PRODUCCIÓN bloqueada: para emitir REAL, escribí exactamente '
                .'la frase EMITIR PRODUCCION en el campo de confirmación. No se firmó ni transmitió nada.');
        }

        Log::info('DTE firmar-transmitir: inicio', [
            'dte_id' => $dte->id,
            'estado' => $dte->estado->value,
            'numero_control' => $dte->numero_control,
            'codigo_generacion' => $dte->codigo_generacion,
            'mock_firma' => (bool) config('dte.firma.mock'),
            'mock_transmision' => (bool) config('dte.transmision.mock'),
        ]);

        // 1. Asegurar el JSON oficial (genera si falta, reusando la misma garantía del correo).
        if (($error = $this->asegurarJsonParaCorreo($dte, $jsonService)) !== null) {
            return $volver->with('error', 'No se puede firmar/transmitir: '.$error);
        }

        // 2. Firmar SOLO si aún está generado; si ya está firmado se salta y se reintenta el envío.
        try {
            if ($dte->estado === EstadoDte::Generado) {
                $firma->firmar($dte);
                $dte->refresh();
            }
        } catch (DteFirmaDeshabilitadaException|DteFirmaException $e) {
            Log::warning('DTE firmar-transmitir: fallo en firma', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return $volver->with('error', 'No se pudo firmar el documento: '.$e->getMessage().' Podés reintentar.');
        }

        // 3. Transmitir el documento firmado.
        try {
            $r = $transmision->transmitir($dte);
        } catch (DteTransmisionDeshabilitadaException $e) {
            Log::warning('DTE firmar-transmitir: transmisión bloqueada', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return $volver->with('error', 'Firmado, pero la transmisión está bloqueada por candados de seguridad: '
                .$e->getMessage().' El documento queda firmado; podés reintentar.');
        } catch (DteTransmisionException $e) {
            Log::warning('DTE firmar-transmitir: fallo en transmisión', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return $volver->with('error', 'Firmado, pero no se pudo transmitir: '.$e->getMessage()
                .' El documento queda firmado; podés reintentar.');
        }

        $dte->refresh();

        Log::info('DTE firmar-transmitir: fin', [
            'dte_id' => $dte->id,
            'resultado' => $r['resultado'],
            'estado_final' => $dte->estado->value,
        ]);

        return $this->respuestaFirmarTransmitir($volver, $r);
    }

    /**
     * Traduce el resultado de la transmisión a un redirect con mensaje claro:
     *  - aceptado  → status (con sello).
     *  - rechazado → error con el motivo completo (mensaje + observaciones del MH).
     *  - transitorio (error_conexion/token_invalido/respuesta_malformada/error_http/…) → error
     *    invitando a reintentar (el documento sigue firmado).
     *
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones?: array<int, string>}  $r
     */
    private function respuestaFirmarTransmitir(RedirectResponse $volver, array $r): RedirectResponse
    {
        $mock = (bool) config('dte.transmision.mock');
        $sufijoMock = $mock ? ' [MODO PRUEBA / MOCK — NO VÁLIDO ANTE HACIENDA]' : '';

        if ($r['resultado'] === 'aceptado') {
            $sello = $r['sello'] ?? null;

            return $volver->with('status', 'Documento ACEPTADO'.($mock ? ' (simulado)' : ' por Hacienda').'.'
                .(filled($sello) ? ' Sello: '.$sello.'.' : '').$sufijoMock);
        }

        if ($r['resultado'] === 'rechazado') {
            $detalle = $r['mensaje'];
            if (! empty($r['observaciones'])) {
                $detalle .= ' — '.implode(' | ', $r['observaciones']);
            }

            return $volver->with('error', 'Documento RECHAZADO'.($mock ? ' (simulado)' : ' por Hacienda').': '
                .$detalle.$sufijoMock);
        }

        // Transitorio: el documento queda firmado y se puede reintentar.
        return $volver->with('error', 'No se completó la transmisión ('.$r['resultado'].'): '.$r['mensaje']
            .' El documento queda firmado; podés reintentar.');
    }

    /**
     * DRY-RUN visual: arma el payload de transmisión y muestra un resumen SEGURO en
     * la pantalla. NO hace HTTP, NO transmite, NO guarda sello, NO cambia estado, NO
     * muestra token/contraseña ni el JWS completo. Solo gestores.
     */
    public function dryRun(Dte $dte, DteTransmisionService $transmision): RedirectResponse
    {
        $this->authorize('verEstadoTecnico', $dte);

        try {
            $resumen = $transmision->dryRun($dte);
        } catch (DteTransmisionException $e) {
            return redirect()
                ->route('facturacion.show', $dte)
                ->with('error', 'No se puede preparar el dry-run: '.$e->getMessage());
        }

        return redirect()
            ->route('facturacion.show', $dte)
            ->with('dry_run', $resumen)
            ->with('status', 'Dry-run ejecutado (solo diagnóstico). NO se transmitió nada a Hacienda.');
    }

    /**
     * DRY-RUN visual de la INVALIDACIÓN (evento anulardte): arma el evento con los datos
     * de config + form, lo valida contra el schema y muestra a qué endpoint iría, el estado
     * de los candados y el evento serializado. Solo lectura: NO firma, NO transmite, NO
     * toca BD, NO muestra secretos (el `documento`/JWS va como marcador). Solo gestores.
     */
    public function dryRunInvalidacion(Request $request, Dte $dte, DteInvalidacionService $invalidacionService): RedirectResponse
    {
        $this->authorize('verInvalidacion', $dte);

        $evento = $this->eventoInvalidacionDesdeRequest($request);

        try {
            $resumen = $invalidacionService->dryRun($dte, $evento);
        } catch (DteInvalidacionException $e) {
            return redirect()
                ->route('facturacion.show', $dte)
                ->with('error', 'No se puede preparar el dry-run de invalidación: '.$e->getMessage());
        }

        return redirect()
            ->route('facturacion.show', $dte)
            ->with('dry_run_invalidacion', $resumen)
            ->with('status', 'Dry-run de invalidación ejecutado (solo diagnóstico). NO se firmó ni transmitió nada.');
    }

    /**
     * Firma el evento de invalidación en MODO MOCK (Fase C): persiste columnas dedicadas
     * (JSON/JWS ficticios + sello MOCK) SIN transmitir a Hacienda, SIN cambiar el estado del
     * DTE y SIN tocar la evidencia de recepción original. La transmisión REAL a apitest NO
     * está disponible desde la UI: se hace solo por consola (dte:invalidacion-real).
     */
    public function invalidarMock(Request $request, Dte $dte, DteInvalidacionMockService $mockService): RedirectResponse
    {
        $this->authorize('invalidarMock', $dte);

        $evento = $this->eventoInvalidacionDesdeRequest($request);
        $confirmar = $request->boolean('confirmar_sin_flag');

        try {
            $r = $mockService->firmarMock($dte, $evento, persistir: true, permitirSinMock: $confirmar);
        } catch (DteInvalidacionException $e) {
            return redirect()
                ->route('facturacion.show', $dte)
                ->with('error', 'No se pudo firmar la invalidación en MOCK: '.$e->getMessage());
        }

        return redirect()
            ->route('facturacion.show', $dte)
            ->with('status', 'Evento de invalidación firmado en MODO PRUEBA (MOCK). Sello: '.$r['sello_invalidacion']
                .'. NO se transmitió nada a Hacienda y el estado del DTE ("'.$r['estado_dte'].'") no cambió.');
    }

    /**
     * Valida los campos del formulario de invalidación (tipo CAT-024, motivo, reemplazo) y
     * construye el EventoInvalidacionData con el responsable/solicitante de config.
     */
    private function eventoInvalidacionDesdeRequest(Request $request): EventoInvalidacionData
    {
        $datos = $request->validate([
            'tipo' => ['required', \Illuminate\Validation\Rule::in(array_map(fn ($t) => $t->value, TipoAnulacionMh::cases()))],
            'motivo' => ['nullable', 'string', 'max:1000', \Illuminate\Validation\Rule::requiredIf(fn () => (int) $request->input('tipo') === TipoAnulacionMh::Otro->value)],
            'reemplazo' => ['nullable', 'string', 'max:100', \Illuminate\Validation\Rule::requiredIf(fn () => (int) $request->input('tipo') === TipoAnulacionMh::ErrorInformacion->value)],
        ], [
            'motivo.required' => 'El motivo en texto es obligatorio para el tipo 3 (Otro).',
            'reemplazo.required' => 'El código de generación del documento de reemplazo es obligatorio para el tipo 1 (Error en la información).',
        ]);

        return $this->eventoInvalidacionDesdeConfig(
            TipoAnulacionMh::from((int) $datos['tipo']),
            $datos['motivo'] ?? null,
            $datos['reemplazo'] ?? null,
        );
    }

    /**
     * Arma el EventoInvalidacionData tomando responsable/solicitante de config('dte.invalidacion.*')
     * (mismos datos que usa el comando dte:invalidacion-mock). El tipo/motivo/reemplazo los
     * aporta quien invoca.
     */
    private function eventoInvalidacionDesdeConfig(TipoAnulacionMh $tipo, ?string $motivo = null, ?string $reemplazo = null): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: $tipo,
            nombreResponsable: config('dte.invalidacion.responsable.nombre') ?: null,
            tipoDocResponsable: config('dte.invalidacion.responsable.tipo_doc') ?: null,
            numDocResponsable: config('dte.invalidacion.responsable.num_doc') ?: null,
            nombreSolicita: config('dte.invalidacion.solicita.nombre') ?: null,
            tipoDocSolicita: config('dte.invalidacion.solicita.tipo_doc') ?: null,
            numDocSolicita: config('dte.invalidacion.solicita.num_doc') ?: null,
            motivoAnulacion: $motivo,
            codigoGeneracionReemplazo: $reemplazo,
        );
    }

    /**
     * Ver (inline) el JWS firmado localmente. Solo lectura del archivo apuntado por
     * json_firmado_path; NO transmite, NO toca BD, NO cambia estado, NO guarda sello.
     */
    public function verJsonFirmado(Dte $dte): Response
    {
        $this->authorize('verJsonFirmado', $dte); // gestor + json_firmado_path presente (DtePolicy)

        [$disco, $ruta] = $this->rutaArchivoSegura(
            $dte->json_firmado_path,
            (string) config('dte.storage.firmados', 'dte/firmados'),
            'documento firmado'
        );

        $contenido = Storage::disk($disco)->get($ruta);

        return response($contenido, 200, [
            'Content-Type' => 'text/plain; charset=utf-8', // JWS compacto (no JSON)
            'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Descargar el JWS firmado localmente. Solo lectura; mismas garantías que
     * verJsonFirmado (no transmite, no toca BD, no cambia estado, no guarda sello).
     */
    public function descargarJsonFirmado(Dte $dte): StreamedResponse
    {
        $this->authorize('verJsonFirmado', $dte);

        [$disco, $ruta] = $this->rutaArchivoSegura(
            $dte->json_firmado_path,
            (string) config('dte.storage.firmados', 'dte/firmados'),
            'documento firmado'
        );

        return Storage::disk($disco)->download($ruta, basename($ruta));
    }

    /**
     * Resuelve y valida la ruta del JSON generado. Garantiza que se lea ÚNICAMENTE el
     * archivo bajo la carpeta oficial de JSON del disco configurado: rechaza path
     * traversal y rutas fuera de esa carpeta, y exige que el archivo exista.
     *
     * @return array{0: string, 1: string}  [disco, ruta relativa al disco]
     */
    private function rutaJsonSegura(Dte $dte): array
    {
        return $this->rutaArchivoSegura(
            $dte->json_generado_path,
            (string) config('dte.storage.json', 'dte/json'),
            'JSON generado'
        );
    }

    /**
     * Resuelve y valida una ruta de archivo del DTE: solo se lee dentro de la carpeta
     * indicada del disco configurado. Rechaza path traversal y rutas fuera de esa
     * carpeta, y exige que el archivo exista. No toca BD.
     *
     * @return array{0: string, 1: string}  [disco, ruta relativa al disco]
     */
    private function rutaArchivoSegura(?string $ruta, string $carpeta, string $tipoLabel): array
    {
        $disco = (string) config('dte.storage.disk', 'local');
        $carpeta = trim($carpeta, '/');
        $rutaNorm = ltrim(str_replace('\\', '/', (string) $ruta), '/');

        // Sin path traversal y dentro de la carpeta oficial.
        if ($rutaNorm === '' || str_contains($rutaNorm, '..') || ! str_starts_with($rutaNorm, $carpeta.'/')) {
            abort(404, 'El '.$tipoLabel.' no está disponible para este documento.');
        }
        if (! Storage::disk($disco)->exists($rutaNorm)) {
            abort(404, 'No se encontró el archivo del '.$tipoLabel.' en el almacenamiento.');
        }

        return [$disco, $rutaNorm];
    }

    public function edit(Request $request, Dte $dte): View
    {
        $this->authorize('update', $dte); // 403 si no es borrador (DtePolicy)

        // La Nota de crédito tiene su propia pantalla (acreditar líneas del original).
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            return $this->editNotaCredito($dte);
        }

        $dte->load(['cliente', 'clienteSucursal', 'lineas', 'establecimiento', 'puntoVenta']);

        // Catálogo de productos disponibles para agregar al borrador (ya visible,
        // con precio resuelto y filtro en vivo en la vista).
        $productosDisponibles = $this->productosDisponibles($dte);

        // Info de retención para los totales (CCF): agente + umbral.
        $esAgenteRetencion = $this->borradores->esAgenteRetencion($dte);
        $umbralRetencion = number_format((float) config('dte.retencion_iva_umbral', 100), 2, '.', '');

        // Cantidad ya agregada por producto (para prellenar el input del catálogo y saber
        // si el botón es "Agregar" o "Actualizar"). producto_id => cantidad entera.
        $cantidadesPorProducto = $dte->lineas
            ->filter(fn (DteLinea $l) => $l->producto_id !== null)
            ->mapWithKeys(fn (DteLinea $l) => [$l->producto_id => (int) $l->cantidad])
            ->all();

        // Aviso SUAVE de OC duplicada: la misma orden de compra ya usada en otro CCF del
        // mismo cliente que ya fue emitido (no borrador) y sigue vigente (no invalidado/
        // anulado). Solo advierte con link; NO bloquea generar (hay casos legítimos).
        $ocDuplicada = null;
        if ($dte->tipo_dte === TipoDte::CreditoFiscal && filled($dte->numero_orden_compra)) {
            $ocDuplicada = Dte::query()
                ->where('id', '!=', $dte->id)
                ->where('tipo_dte', TipoDte::CreditoFiscal->value)
                ->where('cliente_id', $dte->cliente_id)
                ->where('numero_orden_compra', $dte->numero_orden_compra)
                ->whereNotIn('estado', [EstadoDte::Borrador->value, EstadoDte::Invalidado->value])
                ->orderByDesc('id')
                ->first();
        }

        return view('facturacion.edit', compact('dte', 'productosDisponibles', 'esAgenteRetencion', 'umbralRetencion', 'cantidadesPorProducto', 'ocDuplicada'));
    }

    /**
     * Catálogo de productos activos disponibles para agregar al borrador, con el
     * precio ya resuelto (sala → cliente → general) y marcado de origen.
     *
     * Orden: precios especiales del cliente/sala primero (los principales para
     * ese cliente), luego el resto por nombre; los "sin precio" al final y no
     * agregables.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productosDisponibles(Dte $dte): array
    {
        $resolver = app(PrecioProductoResolver::class);

        $clienteEtiqueta = $dte->cliente?->nombre_comercial ?: $dte->cliente?->nombre;
        $salaEtiqueta = $dte->clienteSucursal?->nombre;

        $items = Producto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->map(function (Producto $p) use ($resolver, $dte, $clienteEtiqueta, $salaEtiqueta) {
                $r = $resolver->resolverConOrigen($p, $dte->cliente_id, $dte->cliente_sucursal_id);
                $precio = $r['precio'];
                $esEspecial = $r['origen'] === 'sucursal' || $r['origen'] === 'cliente';
                $sinPrecio = $precio === null || ! is_numeric($precio) || (float) $precio <= 0;

                $origenLabel = match ($r['origen']) {
                    'sucursal' => 'especial '.($salaEtiqueta ?: 'sala'),
                    'cliente' => 'especial '.($clienteEtiqueta ?: 'cliente'),
                    default => 'precio general',
                };

                return [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'codigo_barra' => $p->codigo_barra,
                    'nombre' => $p->nombre,
                    'impuesto' => $p->tipo_impuesto?->label(),
                    'precio_fmt' => $sinPrecio ? null : number_format((float) $precio, 4),
                    'es_especial' => $esEspecial,
                    'origen_label' => $origenLabel,
                    'sin_precio' => $sinPrecio,
                    // Posición en la orden de compra (barcode/nombre); fuera de la lista al final.
                    'oc_rank' => \App\Support\Dte\OrdenProductosOc::rank($p->codigo_barra, $p->nombre),
                    'filtro' => mb_strtolower(trim(($p->codigo ?? '').' '.($p->codigo_barra ?? '').' '.$p->nombre)),
                ];
            });

        return $items
            ->sortBy([
                ['sin_precio', 'asc'],   // los sin precio (no agregables) al final
                ['oc_rank', 'asc'],      // orden fijo de la orden de compra
                ['nombre', 'asc'],       // desempate y fuera-de-lista por nombre
            ])
            ->values()
            ->all();
    }

    public function destroy(Dte $dte): RedirectResponse
    {
        $this->authorize('delete', $dte);

        $dte->delete(); // soft delete, solo borrador (policy + observer)

        return redirect()
            ->route('facturacion.index')
            ->with('status', 'Borrador eliminado.');
    }

    /**
     * DUPLICA un CCF en un borrador NUEVO editable (mismos cliente/sala/emisor/condición/
     * OC/observaciones y copia snapshot de las líneas). No modifica el original ni copia
     * numeración, correlativo, JSON/firma, sello/respuesta MH, correos ni anulaciones.
     * Si la OC copiada ya se usó, el aviso suave de OC duplicada aparece en la edición.
     */
    public function duplicar(Request $request, Dte $dte): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        if ($dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return back()->with('error', 'Solo se puede duplicar un Comprobante de Crédito Fiscal (CCF).');
        }

        try {
            $nuevo = $this->borradores->duplicarCcf($dte, $request->user());
        } catch (OrdenCompraRequeridaException $e) {
            return back()->with('error', 'No se pudo duplicar: '.$e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', 'No se pudo duplicar: '.implode(' ', collect($e->errors())->flatten()->all()));
        }

        return redirect()
            ->route('facturacion.edit', $nuevo)
            ->with('status', 'CCF duplicado como borrador #'.$nuevo->id.' (a partir de '.($dte->numero_control ?? $dte->numero_interno ?? ('#'.$dte->id)).'). Revisá cantidades y orden de compra antes de generar.');
    }

    public function anular(Request $request, Dte $dte, DteAnulacionService $anulacion): RedirectResponse
    {
        $this->authorize('anular', $dte); // gestor + estado generado (DtePolicy)

        $datos = $request->validate([
            'motivo_anulacion' => ['required', \Illuminate\Validation\Rule::in(array_map(fn ($m) => $m->value, \App\Enums\MotivoAnulacion::cases()))],
            'observacion_anulacion' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $anulacion->anular(
                $dte,
                \App\Enums\MotivoAnulacion::from($datos['motivo_anulacion']),
                $datos['observacion_anulacion'] ?? null,
                $request->user(),
            );
        } catch (\App\Exceptions\Dte\AnulacionException $e) {
            return back()->withErrors(['anular' => $e->getMessage()]);
        }

        return redirect()->route('facturacion.show', $dte)->with('status', 'Documento anulado internamente.');
    }

    /**
     * Envía el documento por correo al cliente (MANUAL, síncrono): adjunta el PDF y,
     * si existen, el JSON oficial y el JWS firmado. Registra el intento en el historial
     * (enviado | error). NO transmite a Hacienda ni cambia el estado del DTE.
     */
    public function enviarCorreo(Request $request, Dte $dte, DteJsonService $jsonService): RedirectResponse
    {
        $this->authorize('enviarCorreo', $dte); // gestor + no borrador (DtePolicy)

        $datos = $request->validate([
            'destinatarios' => ['required', 'string', 'max:500'],
        ]);

        $emails = $this->parseDestinatarios($datos['destinatarios']);
        if ($emails === []) {
            return back()->withErrors(['destinatarios' => 'Indicá al menos un correo válido.'])->withInput();
        }

        // No mandar un correo incompleto: el DTE debe tener su JSON oficial guardado. Si falta
        // (documento viejo), se intenta generar automáticamente; si no se puede, se bloquea.
        if (($error = $this->asegurarJsonParaCorreo($dte, $jsonService)) !== null) {
            return back()->withErrors(['destinatarios' => $error])->withInput();
        }

        $envio = $this->encolarEnvio($dte, $emails, $request->user()?->id);
        // Éxito (encolado nuevo o ya en cola): el envío corre en segundo plano (cola), la
        // respuesta NO espera al SMTP. Se redirige EN LA MISMA PESTAÑA al PDF para imprimir
        // (sin window.open, que el navegador puede bloquear). Solo se abre el PDF si encoló.
        $mensaje = $envio === null
            ? 'El correo ya estaba en cola para esos destinatarios. Abriendo PDF para imprimir.'
            : 'Correo en cola para envío a: '.implode(', ', $emails).'. Abriendo PDF para imprimir.';

        return redirect()->route('facturacion.pdf', $dte)->with('status', $mensaje);
    }

    /** Reenvía el documento con los destinatarios de un envío previo (nuevo registro). */
    public function reenviarCorreo(Request $request, Dte $dte, DteEnvio $envio): RedirectResponse
    {
        $this->authorize('enviarCorreo', $dte);
        abort_unless($envio->dte_id === $dte->id, 404);

        $emails = $envio->destinatarios ?: array_values(array_filter([$envio->destinatario]));
        if ($emails === []) {
            return back()->with('error', 'El envío no tiene destinatarios para reenviar.');
        }

        $envio = $this->encolarEnvio($dte, $emails, $request->user()?->id);
        if ($envio === null) {
            return back()->with('status', 'Ya hay un envío pendiente para esos destinatarios; no se duplicó.');
        }

        return back()->with('status', 'Reenvío encolado para: '.implode(', ', $emails).'.');
    }

    /**
     * Envío RÁPIDO (un clic) del documento al correo del cliente/sala. Resuelve el
     * destinatario (sala → cliente); si no hay uno válido, muestra un mensaje claro y NO
     * intenta enviar. Reusa el MISMO pipeline encolado que enviarCorreo (PDF + JSON/JWS,
     * historial DteEnvio, job en cola). No transmite a Hacienda ni cambia el estado.
     */
    public function enviarCorreoCliente(Dte $dte, DteJsonService $jsonService): RedirectResponse
    {
        $this->authorize('enviarCorreo', $dte); // gestor + no borrador (DtePolicy)

        $email = $dte->clienteSucursal?->correo ?: $dte->cliente?->correo;
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $quien = $dte->clienteSucursal ? 'La sala/cliente' : 'El cliente';

            return back()->with('error', $quien.' no tiene un correo válido configurado. Agregá el correo para poder enviar el documento.');
        }

        // El correo no sale incompleto: garantizar el JSON oficial (se genera si falta).
        if (($error = $this->asegurarJsonParaCorreo($dte, $jsonService)) !== null) {
            return back()->with('error', $error);
        }

        $envio = $this->encolarEnvio($dte, [$email], request()->user()?->id);
        if ($envio === null) {
            return back()->with('status', 'Ya hay un envío pendiente para '.$email.'; no se duplicó.');
        }

        return back()->with('status', 'Documento encolado para envío por correo a '.$email.'.');
    }

    /**
     * Crea el registro 'pendiente' y ENCOLA el envío (la UI no espera al SMTP).
     * Anti-duplicado: si ya hay un envío PENDIENTE con los MISMOS destinatarios para este DTE,
     * NO crea otro (devuelve null) para no encolar jobs repetidos.
     */
    private function encolarEnvio(Dte $dte, array $emails, ?int $userId): ?DteEnvio
    {
        $duplicado = $dte->envios()->where('estado', 'pendiente')->get()
            ->first(fn (DteEnvio $e) => $this->mismosDestinatarios($e->destinatarios ?: array_filter([$e->destinatario]), $emails));
        if ($duplicado !== null) {
            return null;
        }

        $envio = $dte->envios()->create([
            'destinatario' => $emails[0],
            'destinatarios' => $emails,
            'estado' => 'pendiente',
            'user_id' => $userId,
        ]);

        \App\Jobs\EnviarDteCorreo::dispatch($envio->id);

        return $envio;
    }

    /**
     * Garantiza que el DTE tenga su JSON oficial guardado antes de encolar el correo.
     * Si ya lo tiene, no hace nada. Si falta y el documento está en condiciones (tipo
     * soportado + estado generado), lo genera al vuelo. Si no se puede, devuelve el
     * mensaje de error para mostrar al usuario; null = todo OK.
     */
    private function asegurarJsonParaCorreo(Dte $dte, DteJsonService $jsonService): ?string
    {
        if (filled($dte->json_generado_path)) {
            return null;
        }

        $faltante = 'Este DTE no tiene JSON generado. Regenerá el JSON antes de enviar.';

        if (! $jsonService->soporta($dte->tipo_dte) || $dte->estado !== EstadoDte::Generado) {
            return $faltante;
        }

        try {
            $jsonService->generar($dte); // asigna numeración oficial + guarda json_generado_path
        } catch (\Throwable $e) { // schema inválido, no mapeable, no serializable, etc.
            return $faltante;
        }

        return null;
    }

    /** ¿Dos listas de destinatarios son el mismo conjunto (sin importar orden/duplicados)? */
    private function mismosDestinatarios(array $a, array $b): bool
    {
        $norm = fn (array $x) => collect($x)->map(fn ($e) => strtolower(trim((string) $e)))->unique()->sort()->values()->all();

        return $norm($a) === $norm($b);
    }

    /**
     * Parsea correos separados por coma, punto y coma o salto de línea; normaliza,
     * deduplica y descarta inválidos.
     *
     * @return array<int, string>
     */
    private function parseDestinatarios(string $texto): array
    {
        $emails = [];
        foreach (preg_split('/[,;\r\n]+/', $texto) ?: [] as $p) {
            $e = strtolower(trim($p));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && ! in_array($e, $emails, true)) {
                $emails[] = $e;
            }
        }

        return $emails;
    }

    public function generar(Dte $dte, DteGeneracionService $generacion): RedirectResponse
    {
        // 'update' exige borrador + gestor (administrador/facturación).
        $this->authorize('update', $dte);

        try {
            $generacion->generar($dte, request()->user());
        } catch (GeneracionException $e) {
            return back()->withErrors(['generar' => $e->getMessage()]);
        }

        return redirect()
            ->route('facturacion.show', $dte)
            ->with('status', 'Documento generado. Número interno: '.$dte->numero_interno);
    }

    /**
     * Crea una Nota de crédito (05) a partir de un CCF original ($dte = CCF).
     * El tipo interno define el flujo (por productos vs. por monto/concepto).
     */
    public function storeNotaCredito(Request $request, Dte $dte): RedirectResponse
    {
        $this->authorize('create', Dte::class);

        $datos = $request->validate([
            'tipo' => ['required', \Illuminate\Validation\Rule::in(array_map(fn ($t) => $t->value, \App\Enums\TipoNotaCredito::cases()))],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ], [
            'tipo.required' => 'Seleccione el tipo de nota de crédito.',
            'tipo.in' => 'Seleccione el tipo de nota de crédito.',
        ]);

        // crearNotaCredito lanza ValidationException si el original no es válido.
        $nc = $this->borradores->crearNotaCredito($dte, $datos, $request->user());

        return redirect()->route('facturacion.edit', $nc)->with('status', $this->mensajeNotaCredito($nc));
    }

    /** Mensaje de guía según la modalidad de la NC recién creada. */
    private function mensajeNotaCredito(Dte $nc): string
    {
        return match (true) {
            $nc->tipo_nota_credito->esPorProductos() => 'Nota de crédito creada. Acredita las líneas del documento original.',
            $nc->tipo_nota_credito->esPorAveria() => 'Nota de crédito por avería creada. Agrega los productos del catálogo.',
            default => 'Nota de crédito creada. Agrega los conceptos del ajuste.',
        };
    }

    /**
     * Agrega un concepto manual a una NC por monto (pronto pago / descuento / ajuste).
     */
    public function agregarConceptoNc(Request $request, Dte $dte): RedirectResponse
    {
        $this->authorize('update', $dte);

        $this->borradores->agregarConceptoNotaCredito($dte, $request->only(['descripcion', 'monto', 'tipo_impuesto']));

        return back()->with('status', 'Concepto agregado.');
    }

    /**
     * Acredita una línea del documento original en la NC.
     * $dte = nota de crédito; $linea = línea del CCF original.
     */
    public function acreditarLinea(Request $request, Dte $dte, DteLinea $linea): RedirectResponse
    {
        $this->authorize('update', $dte);
        abort_unless($dte->dte_relacionado_id && (int) $linea->dte_id === (int) $dte->dte_relacionado_id, 404);

        $request->validate(['cantidad' => ['required', 'numeric', 'gt:0']]);

        try {
            $this->borradores->acreditarLinea($dte, $linea, $request->input('cantidad'));
        } catch (SaldoAcreditableExcedidoException $e) {
            return back()->withErrors(['cantidad' => $e->getMessage()]);
        }

        return back()->with('status', 'Línea acreditada.');
    }

    private function editNotaCredito(Dte $nc): View
    {
        $nc->load(['cliente', 'clienteSucursal', 'lineas', 'dteRelacionado.lineas']);
        $original = $nc->dteRelacionado;

        $porProductos = $nc->tipo_nota_credito?->esPorProductos() ?? false;
        $porAveria = $nc->tipo_nota_credito?->esPorAveria() ?? false;

        // Avería: catálogo de productos libres (mismo helper que el CCF).
        $productosDisponibles = $porAveria ? $this->productosDisponibles($nc) : [];

        // Saldo acreditable por línea del original (solo NC por productos).
        $lineasOriginales = collect();
        if ($porProductos && $original) {
            $lineasOriginales = $original->lineas->map(function (DteLinea $lo) {
                // El saldo ignora NC anuladas (invalidado).
                $acreditado = (string) (DteLinea::where('dte_linea_original_id', $lo->id)
                    ->whereHas('dte', fn ($q) => $q->where('estado', '!=', \App\Enums\EstadoDte::Invalidado->value))
                    ->sum('cantidad') ?? 0);

                return [
                    'linea' => $lo,
                    'acreditado' => $acreditado,
                    'disponible' => \App\Support\Dinero::redondear(
                        \App\Support\Dinero::restar(\App\Support\Dinero::de($lo->cantidad), $acreditado), 4
                    ),
                ];
            });
        }

        $tiposImpuesto = \App\Enums\TipoImpuesto::opciones();

        return view('facturacion.edit-nc', compact(
            'nc', 'original', 'lineasOriginales', 'porProductos', 'porAveria', 'productosDisponibles', 'tiposImpuesto'
        ));
    }

    /**
     * Agrega un producto del catálogo a una NC por AVERÍA (cualquier producto
     * activo, no limitado al CCF original). Reutiliza la validación de cantidad
     * (entero ≥ 1) del FormRequest de líneas.
     */
    public function agregarProductoAveria(AgregarLineaDteRequest $request, Dte $dte): RedirectResponse
    {
        $this->authorize('update', $dte);

        $producto = Producto::findOrFail($request->integer('producto_id'));

        try {
            $this->borradores->agregarProductoNotaCreditoAveria($dte, $producto, $request->integer('cantidad'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', 'Producto agregado a la nota de crédito por avería.');
    }

    /**
     * Fija la cantidad de un producto en el borrador (auto-agregar por cantidad):
     * cantidad > 0 agrega o actualiza la línea (idempotente por producto, no duplica);
     * cantidad 0/vacía quita la línea si existía. Reusa DteBorradorService; no cambia
     * reglas fiscales, no firma ni transmite.
     */
    public function setCantidadProducto(Request $request, Dte $dte, Producto $producto): RedirectResponse
    {
        $this->authorize('update', $dte);

        // En una NC los productos entran por acreditación o por el catálogo de avería.
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            return back()->withErrors(['cantidad' => 'Use acreditar líneas o el catálogo de avería para una nota de crédito.']);
        }

        $request->validate(['cantidad' => ['nullable', 'integer', 'min:0', 'max:999999']]);

        $raw = $request->input('cantidad');
        $cantidad = ($raw === null || $raw === '') ? null : (int) $raw;

        // Al agregar/actualizar con cantidad, el producto debe tener precio aplicable
        // (mismo criterio que storeLinea). Sin precio no se agrega.
        if ($cantidad !== null && $cantidad > 0) {
            $r = app(PrecioProductoResolver::class)->resolverConOrigen($producto, $dte->cliente_id, $dte->cliente_sucursal_id);
            if ($r['precio'] === null || ! is_numeric($r['precio']) || (float) $r['precio'] <= 0) {
                return back()->withErrors(['cantidad' => 'El producto no tiene un precio aplicable para este cliente; no se puede agregar.']);
            }
        }

        try {
            $res = $this->borradores->establecerCantidadProducto($dte, $producto, $cantidad);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $mensaje = match ($res['accion']) {
            'agregada' => 'Producto agregado.',
            'actualizada' => 'Cantidad actualizada.',
            'eliminada' => 'Producto quitado del borrador.',
            default => null,
        };

        return $mensaje ? back()->with('status', $mensaje) : back();
    }

    /**
     * Modo ESCÁNER: busca el producto por código de barras EXACTO y lo agrega al
     * borrador; si ya estaba en las líneas, SUMA 1 a su cantidad (no duplica línea).
     * Reusa establecerCantidadProducto (misma idempotencia, snapshot de precio y
     * recálculo de totales que el catálogo manual); no cambia reglas fiscales.
     */
    public function escanearProducto(Request $request, Dte $dte): RedirectResponse
    {
        $this->authorize('update', $dte);

        // En una NC los productos entran por acreditación o por el catálogo de avería.
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            return back()->withErrors(['codigo_barra' => 'Use acreditar líneas o el catálogo de avería para una nota de crédito.']);
        }

        $datos = $request->validate(['codigo_barra' => ['required', 'string', 'max:60']]);
        $codigo = trim($datos['codigo_barra']);

        $producto = Producto::where('codigo_barra', $codigo)->first();
        if (! $producto) {
            return back()->withErrors(['codigo_barra' => 'No se encontró ningún producto con el código de barras "'.$codigo.'".']);
        }
        if (! $producto->activo) {
            return back()->withErrors(['codigo_barra' => 'El producto "'.$producto->nombre.'" está inactivo; no se puede agregar.']);
        }

        // Mismo criterio que storeLinea/setCantidadProducto: sin precio aplicable no se agrega.
        $r = app(PrecioProductoResolver::class)->resolverConOrigen($producto, $dte->cliente_id, $dte->cliente_sucursal_id);
        if ($r['precio'] === null || ! is_numeric($r['precio']) || (float) $r['precio'] <= 0) {
            return back()->withErrors(['codigo_barra' => 'El producto "'.$producto->nombre.'" no tiene un precio aplicable para este cliente; no se puede agregar.']);
        }

        $cantidadActual = (int) ($dte->lineas()->where('producto_id', $producto->id)->value('cantidad') ?? 0);
        $this->borradores->establecerCantidadProducto($dte, $producto, $cantidadActual + 1);

        $mensaje = $cantidadActual > 0
            ? 'Escaneado: '.$producto->nombre.' — cantidad actualizada a '.($cantidadActual + 1).'.'
            : 'Escaneado: '.$producto->nombre.' agregado (cantidad 1).';

        return back()->with('status', $mensaje);
    }

    public function storeLinea(AgregarLineaDteRequest $request, Dte $dte): RedirectResponse
    {
        $this->authorize('update', $dte);

        // En una Nota de crédito los productos entran por acreditación (devolución/
        // faltante) o por el catálogo de avería, no por esta ruta.
        if ($dte->tipo_dte === TipoDte::NotaCredito) {
            return back()->withErrors(['producto_id' => 'Use acreditar líneas o el catálogo de avería para una nota de crédito.']);
        }

        $producto = Producto::findOrFail($request->integer('producto_id'));

        // No se permiten productos sin precio aplicable (general ni especial).
        $r = app(PrecioProductoResolver::class)->resolverConOrigen($producto, $dte->cliente_id, $dte->cliente_sucursal_id);
        if ($r['precio'] === null || ! is_numeric($r['precio']) || (float) $r['precio'] <= 0) {
            return back()->withErrors(['producto_id' => 'El producto no tiene un precio aplicable para este cliente; no se puede agregar.']);
        }

        // Flujo normal: cantidad entera; precio resuelto por cliente/sucursal;
        // sin descuento por línea (el descuento global viene del cliente/sala).
        $this->borradores->agregarLineaDesdeProducto(
            $dte,
            $producto,
            $request->integer('cantidad'),
        );

        return back()->with('status', 'Línea agregada.');
    }

    public function updateLinea(ActualizarLineaDteRequest $request, Dte $dte, DteLinea $linea): RedirectResponse
    {
        $this->authorize('update', $dte);
        $this->verificarLineaDelDte($dte, $linea);

        $this->borradores->actualizarLinea($linea, $request->validated());

        return back()->with('status', 'Línea actualizada.');
    }

    public function destroyLinea(Dte $dte, DteLinea $linea): RedirectResponse
    {
        $this->authorize('update', $dte);
        $this->verificarLineaDelDte($dte, $linea);

        $this->borradores->eliminarLinea($linea);

        return back()->with('status', 'Línea eliminada.');
    }

    private function verificarLineaDelDte(Dte $dte, DteLinea $linea): void
    {
        abort_unless($linea->dte_id === $dte->id, 404);
    }

    /**
     * Aplana clientes + sus sucursales activas en opciones para el buscador del CCF.
     * Cada opción lleva el cliente fiscal y, opcionalmente, la sala comercial.
     *
     * @param  \Illuminate\Support\Collection<int, Cliente>  $clientes
     * @return array<int, array<string, mixed>>
     */
    private function opcionesClienteSucursal($clientes): array
    {
        $opciones = [];

        foreach ($clientes as $cliente) {
            // Opción base: cliente sin sala específica.
            $opciones[] = $this->opcionCliente($cliente, null);

            // Una opción por cada sala activa.
            foreach ($cliente->sucursales as $sucursal) {
                $opciones[] = $this->opcionCliente($cliente, $sucursal);
            }
        }

        return $opciones;
    }

    /** @return array<string, mixed> */
    private function opcionCliente(Cliente $cliente, ?ClienteSucursal $sucursal): array
    {
        $condicion = $this->condicionAplicada($cliente, $sucursal);

        return [
            'key' => $cliente->id.'-'.($sucursal?->id ?? 0),
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sucursal?->id,
            'nombre' => $cliente->nombre,
            'sucursal' => $sucursal?->nombre,
            'num_documento' => $cliente->num_documento,
            'nrc' => $cliente->nrc,
            // Regla única (OR): la exige el cliente o la sala. Coincide con el backend.
            'requiere_oc' => \App\Support\Dte\ReglaOrdenCompra::requerida($cliente, $sucursal),
            // Agente de retención efectivo (override de sala → cliente).
            'es_agente_retencion' => $this->esAgenteRetencionEfectivo($cliente, $sucursal),
            // Valores aplicados (informativos en el formulario). El descuento es %.
            'descuento_porcentaje' => $this->descuentoPorcentajeAplicado($cliente, $sucursal),
            'condicion' => $condicion,
            'condicion_label' => CondicionPago::tryFrom($condicion)?->label() ?? '—',
        ];
    }

    /** Agente de retención efectivo: override de la sala (no null) → cliente → false. */
    private function esAgenteRetencionEfectivo(?Cliente $cliente, ?ClienteSucursal $sucursal): bool
    {
        if ($sucursal && $sucursal->es_agente_retencion !== null) {
            return (bool) $sucursal->es_agente_retencion;
        }

        return (bool) $cliente?->es_agente_retencion;
    }

    /**
     * Porcentaje de descuento global a aplicar (0–100): sala → cliente → 0.
     * Es un PORCENTAJE, no un monto: "5.00" significa 5%.
     */
    private function descuentoPorcentajeAplicado(?Cliente $cliente, ?ClienteSucursal $sucursal): string
    {
        $valor = $sucursal?->descuento_global_default ?? $cliente?->descuento_global_default ?? 0;
        $valor = max(0.0, min(100.0, (float) $valor));

        return number_format($valor, 2, '.', '');
    }

    /** Condición de operación a aplicar: sala → cliente → default contribuyente. */
    private function condicionAplicada(?Cliente $cliente, ?ClienteSucursal $sucursal): int
    {
        return (int) ($sucursal?->condicion_operacion_default
            ?? $cliente?->condicion_operacion_default
            ?? config('dte.condicion_operacion_default_contribuyente', 2));
    }

    /**
     * Mapea clientes a opciones para el buscador, con descuento/condición aplicados
     * (a nivel cliente; informativos en el formulario).
     *
     * @param  \Illuminate\Support\Collection<int, Cliente>  $clientes
     * @return array<int, array<string, mixed>>
     */
    private function clientesInformativos($clientes): array
    {
        return $clientes->map(function (Cliente $c) {
            $condicion = $this->condicionAplicada($c, null);

            return [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'nombre_comercial' => $c->nombre_comercial,
                'num_documento' => $c->num_documento,
                'nrc' => $c->nrc,
                'correo' => $c->correo,
                'descuento_porcentaje' => $this->descuentoPorcentajeAplicado($c, null),
                'condicion_label' => CondicionPago::tryFrom($condicion)?->label() ?? '—',
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function datosFormularioCcf(): array
    {
        // Solo salas que permiten CCF.
        $clientes = Cliente::query()
            ->where('tipo_cliente', TipoCliente::Contribuyente->value)
            ->where('activo', true)
            ->with(['sucursales' => fn ($q) => $q->where('activo', true)->where('permite_ccf', true)->orderBy('nombre')])
            ->orderBy('nombre')
            ->get();

        return [
            'opcionesCliente' => $this->opcionesClienteSucursal($clientes),
            'establecimientos' => Establecimiento::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'puntosVenta' => PuntoVenta::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'establecimiento_id']),
        ];
    }

    /** @return array<string, mixed> */
    private function datosFormularioFactura(): array
    {
        // Factura 01: cliente opcional; si se elige, nacional (consumidor final o contribuyente).
        $clientes = Cliente::query()
            ->whereIn('tipo_cliente', [TipoCliente::ConsumidorFinal->value, TipoCliente::Contribuyente->value])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return [
            'clientes' => $this->clientesInformativos($clientes),
            'establecimientos' => Establecimiento::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'puntosVenta' => PuntoVenta::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'establecimiento_id']),
        ];
    }

    /** @return array<string, mixed> */
    private function datosFormularioExportacion(): array
    {
        // Factura de exportación: cliente de exportación OBLIGATORIO.
        $clientes = Cliente::query()
            ->where('tipo_cliente', TipoCliente::Exportacion->value)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return [
            'clientes' => $this->clientesInformativos($clientes),
            'establecimientos' => Establecimiento::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'puntosVenta' => PuntoVenta::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'establecimiento_id']),
        ];
    }

    /** @return array<string, mixed> */
    private function datosFormularioNotaCredito(): array
    {
        // CCF ACEPTADOS REALMENTE por Hacienda (sello real + fecha_procesamiento_mh; no
        // mock/simulado ni solo locales) que se pueden vincular en el buscador del formulario.
        $ccfs = Dte::query()
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->aceptadoRealMh()
            ->with('cliente:id,nombre,num_documento,nrc')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'numero_interno', 'cliente_id', 'cliente_sucursal_id', 'numero_orden_compra', 'fecha_emision', 'total_pagar', 'establecimiento_id', 'punto_venta_id']);

        // Tipos de NC que afectan productos del CCF (exigen documento relacionado).
        $tiposPorProductos = [];
        foreach (TipoNotaCredito::cases() as $t) {
            if ($t->esPorProductos()) {
                $tiposPorProductos[] = $t->value;
            }
        }

        // Receptores: contribuyentes con salas que PERMITEN nota de crédito.
        $clientes = Cliente::query()
            ->where('tipo_cliente', TipoCliente::Contribuyente->value)
            ->where('activo', true)
            ->with(['sucursales' => fn ($q) => $q->where('activo', true)->where('permite_nota_credito', true)->orderBy('nombre')])
            ->orderBy('nombre')
            ->get();

        return [
            'opcionesCliente' => $this->opcionesClienteSucursal($clientes),
            'opcionesCcf' => $ccfs->map(fn (Dte $c) => [
                'id' => $c->id,
                'numero' => $c->numero_interno ?? ('#'.$c->id),
                'cliente_id' => $c->cliente_id,
                'cliente_sucursal_id' => $c->cliente_sucursal_id,
                'cliente_nombre' => $c->cliente?->nombre,
                'num_documento' => $c->cliente?->num_documento,
                'orden_compra' => $c->numero_orden_compra,
                'fecha' => $c->fecha_emision?->format('d/m/Y'),
                'total' => number_format((float) $c->total_pagar, 2),
                'establecimiento_id' => $c->establecimiento_id,
                'punto_venta_id' => $c->punto_venta_id,
            ])->all(),
            'tiposNc' => TipoNotaCredito::opciones(),
            'tiposPorProductos' => $tiposPorProductos,
            'establecimientos' => Establecimiento::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre']),
            'puntosVenta' => PuntoVenta::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'establecimiento_id']),
        ];
    }
}
