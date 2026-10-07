<?php

namespace App\Http\Controllers\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoDte;
use App\Exceptions\CopiaArchivadaInservibleException;
use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudItem;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1;
use App\Services\Cobros\NotasDelQuedan;
use App\Services\Cobros\RevisionHistoricaService;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Cobros\SugerenciasAlbaran;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Services\Ppq\ActualizarPpqConTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\NcPosterioresLotePpq;
use App\Services\RegistroDescargas;
use App\Support\Dinero;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * COBROS CALLEJA: la bandeja donde se ve cada factura desde que Hacienda la acepta hasta
 * que el cliente la paga, incluidas las que nunca se presentaron.
 *
 * Es la pantalla PRINCIPAL del cobro. Rutas sigue existiendo y sigue mostrando documentos,
 * pero desde acá se decide qué se presenta y desde acá se ve qué falta cobrar: en Rutas la
 * pregunta es «qué llevo hoy», y esa es otra.
 *
 * Los dos ejes —presentación y pago— se muestran SIEMPRE juntos y nunca se mezclan en una
 * sola columna: una factura puede estar recibida y sin pagar, o pagada sin haberse
 * presentado nunca por este circuito. Y las observaciones son un tercer eje que no se
 * pierde al mover ninguno de los otros dos.
 *
 * No emite, no firma, no transmite, no manda correos y no toca ningún valor fiscal.
 */
class CobrosController extends Controller
{
    public const MAX_PPQ = 500;

    /** Claves de filtro aceptadas. Todas OPCIONALES: acotan lo que se ve, no lo que existe. */
    private const FILTROS = ['presentacion', 'pago', 'vinculacion', 'desde', 'hasta', 'sala', 'tipo', 'q', 'revisar', 'pago_revision', 'mes', 'etapa'];

    /** Etapas de la bandeja (pestañas): qué le falta a cada CCF. */
    public const ETAPAS = [
        'no_entregados' => 'No entregados',
        'listos' => 'Entregados, por presentar',
        'presentados' => 'En PPQ / presentados',
        'pagados' => 'Pagados',
        'diferencias' => 'Pagos con diferencia',
    ];

    /** Documentos por página de la bandeja. */
    private const POR_PAGINA = 25;

    /** Clave de sesión de la última vista previa de solicitud, por cliente. */
    private const SESION_PREVIA = 'cobros_solicitud_previa';

    /** Máximo de NC que se listan en la ficha de un CCF; el total se informa aparte. */
    private const NOTAS_EN_FICHA = 50;

    /**
     * Filtros del historial de SOLICITUDES, con prefijo propio: conviven en la misma URL con
     * los de documentos sin pisarse.
     */
    private const FILTROS_SOLICITUD = ['sol_q', 'sol_estado', 'sol_desde', 'sol_hasta'];

    /** Solicitudes por página del historial. */
    private const SOLICITUDES_POR_PAGINA = 20;

    /** Renglones por página en la ficha de una solicitud. */
    private const RENGLONES_POR_PAGINA = 50;

    /** Descargas preparadas por página en la ficha de una solicitud. */
    private const DESCARGAS_POR_PAGINA = 20;

    /** @var array<int, array<int, int>> por cliente, ver {@see saldadosConNc()} */
    private array $saldadosConNc = [];

    public function __construct(
        private readonly AltaCobrosService $alta,
        private readonly VinculadorAlbaranes $vinculador,
        private readonly SolicitudCobroService $solicitudes,
    ) {}

    /** La bandeja. */
    public function index(Request $request): View
    {
        $clientes = $this->clientes();
        $cliente = $this->clienteElegido($request, $clientes);
        $filtros = $this->filtros($request);
        $filtrosSolicitud = $this->filtrosSolicitud($request);

        if ($cliente === null) {
            return view('cobros.index', [
                'clientes' => $clientes,
                'cliente' => null,
                'meses' => [],
                'etapas' => [],
                'notasPorCcf' => collect(),
                'filtros' => $filtros,
                'hayFiltros' => false,
                'filtrosSolicitud' => $filtrosSolicitud,
                'hayFiltrosSolicitud' => false,
                'documentos' => null,
                'contadores' => [],
                'solicitudes' => null,
                'ultimasDescargas' => [],
                'ajustes' => collect(),
                'correos' => collect(),
                'salas' => [],
            ]);
        }

        // Paginación REAL sobre la consulta filtrada: antes se cortaba en 500 y el resto no
        // se veía. El orden por recencia termina en `id`, así que es estable entre
        // páginas (nada se repite ni se pierde). `withQueryString()` conserva cliente y
        // filtros en los enlaces de página.
        $documentos = $this->consulta($cliente, $filtros)
            ->with(['albaran', 'solicitud', 'eventos', 'dte:id,numero_orden_compra,estado'])
            ->porRecencia()
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // Historial COMPLETO de solicitudes, paginado con su propio parámetro (no mueve la
        // página de documentos). Antes se cortaba en 20 y las viejas no se podían abrir.
        // Orden por id: estable entre páginas.
        $solicitudes = $this->consultaSolicitudes($cliente, $filtrosSolicitud)
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(self::SOLICITUDES_POR_PAGINA, ['*'], 'solicitudes_page')
            ->withQueryString();

        // Lo que puede entrar en un PPQ: de TODO el cliente (para recordar lo marcado en otras
        // páginas) y dentro del filtro actual (para «Seleccionar todas las pendientes»).
        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);
        $clavesPpq = $elegibilidad->claves();
        $listosPpq = $elegibilidad->listos(CobroDocumento::deCliente($cliente->id)->sinInvalidadosRetirables()
            ->whereKeyNot($this->saldadosConNc($cliente))
            ->when(config('dte.ambiente') === '01', fn ($q) => $q->whereDoesntHave('dte', fn ($d) => $d->where('ambiente', '00'))), $clavesPpq);
        $listosEnFiltro = $elegibilidad->listos($this->consulta($cliente, $filtros), $clavesPpq);
        $avisosPpq = $elegibilidad->avisos($listosPpq->keys());
        $duplicadosEnFiltro = $listosEnFiltro->filter(fn ($monto, $id) => ($avisosPpq[$id]['duplicado'] ?? null) !== null)->count();
        $listosEnFiltro = $listosEnFiltro->reject(fn ($monto, $id) => ($avisosPpq[$id]['duplicado'] ?? null) !== null);
        $totalListosEnFiltro = '0';
        foreach ($listosEnFiltro as $monto) {
            $totalListosEnFiltro = Dinero::sumar($totalListosEnFiltro, $monto);
        }

        return view('cobros.index', [
            'avisosPpq' => $avisosPpq,
            'duplicadosEnFiltro' => $duplicadosEnFiltro,
            'listosPpq' => $listosPpq,
            'ncSueltas' => app(NcPosterioresLotePpq::class)->sueltasDelCliente($cliente),
            'listosEnFiltro' => $listosEnFiltro,
            'cantidadListosEnFiltro' => $listosEnFiltro->count(),
            'totalListosEnFiltro' => Dinero::redondear($totalListosEnFiltro),
            'motivosPpq' => $documentos->getCollection()->mapWithKeys(fn ($doc) => [$doc->id => $elegibilidad->motivo($doc, $clavesPpq)]),
            'clientes' => $clientes,
            'cliente' => $cliente,
            // Sin Gmail no entran albaranes: se avisa arriba para que no pase inadvertido.
            'gmailDesconectado' => (bool) config('ppq.gmail.enabled') && ! app(GmailClient::class)->disponible(),
            'meses' => $this->meses($cliente, $filtros),
            'etapas' => $this->conteoEtapas($cliente, $filtros),
            'notasPorCcf' => $this->notasPorCcf($documentos->getCollection()),
            'filtros' => $filtros,
            'hayFiltros' => collect($filtros)->filter(fn ($v) => filled($v))->isNotEmpty(),
            'filtrosSolicitud' => $filtrosSolicitud,
            'hayFiltrosSolicitud' => collect($filtrosSolicitud)->filter(fn ($v) => filled($v))->isNotEmpty(),
            'documentos' => $documentos,
            'contadores' => $this->contadores($cliente),
            'solicitudes' => $solicitudes,
            // Última descarga PREPARADA de cada solicitud de ESTA página, en una sola consulta.
            'ultimasDescargas' => app(RegistroDescargas::class)->ultimas($solicitudes->getCollection()),
            'ajustes' => CobroAjuste::where('cliente_id', $cliente->id)
                ->with(['solicitud:id,referencia', 'notaCredito'])->latest('id')->limit(20)->get(),
            'correos' => CobroCorreo::where('cliente_id', $cliente->id)
                ->latest('id')->limit(20)->get(),
            'salas' => $this->salas($cliente),
        ]);
    }

    /** Ficha de un documento: su historia completa. */
    public function show(Request $request, CobroDocumento $documento, SugerenciasAlbaran $sugerencias): View
    {
        $documento->loadMissing(['albaran', 'solicitud', 'eventos.usuario', 'dte', 'cliente']);

        // «Corregir vínculo»: solo para quien puede vincular, en un CCF vigente.
        $corregible = $documento->tipo_dte === '03' && ! $documento->estaInvalidado()
            && $request->user()?->can('ppq.gestionar');
        $movible = $documento->ppq_albaran_id === null || $this->vinculador->albaranMovible($documento);

        return view('cobros.show', [
            'documento' => $documento,
            'veredicto' => $this->vinculador->auditar($documento),
            'notas' => $this->notasDelCcf($documento),
            'corregible' => $corregible,
            'movible' => $movible,
            'sugeridos' => $corregible && $movible ? $sugerencias->para($documento) : [],
        ]);
    }

    /** Albaranes de entrega por número, para elegir uno que no salió entre los sugeridos. */
    public function buscarAlbaranes(Request $request, CobroDocumento $documento, SugerenciasAlbaran $sugerencias): JsonResponse
    {
        $texto = $request->validate(['q' => ['nullable', 'string', 'max:40']])['q'] ?? '';

        return response()->json($sugerencias->buscar($documento, $texto));
    }

    /**
     * Ficha de SOLO LECTURA de una solicitud: los renglones CONGELADOS que entraron en su
     * archivo y, por separado, cada hecho posterior (descargas preparadas, presentación
     * declarada, acuse, correos y ajustes). Abrirla no registra nada ni cambia estados.
     *
     * Todo sale de relaciones persistidas: `cobro_solicitud_items`, `cobro_correos` y
     * `cobro_ajustes` por su `cobro_solicitud_id`, y la bitácora por su sujeto. Nada se
     * atribuye por texto, importe ni orden de compra.
     */
    public function showSolicitud(CobroSolicitud $solicitud): View
    {
        $solicitud->loadMissing([
            'cliente:id,nombre',
            'usuario:id,name',
            'presentadaPor:id,name',
            'corrigeA:id,referencia',
            'correcciones:id,referencia,corrige_a_id,created_at',
        ])->loadCount('items');

        // Los renglones, con el documento tal como está HOY (pago, observaciones del
        // cliente). El dato presentado es el del renglón; el del documento es el actual.
        $renglones = $solicitud->items()
            ->with(['documento' => fn ($q) => $q
                ->select(['id', 'cliente_id', 'dte_id', 'tipo_dte', 'numero_control', 'fecha_emision', 'monto', 'monto_pagado', 'pago_estado', 'fecha_pago', 'cobro_solicitud_id', 'observaciones'])
                ->withCount(['eventos as observaciones_cliente_count' => fn ($e) => $e->where('tipo', TipoEventoCobro::Observacion->value)])])
            ->paginate(self::RENGLONES_POR_PAGINA, ['*'], 'renglones_page')
            ->withQueryString();

        $registro = app(RegistroDescargas::class);

        return view('cobros.solicitud', [
            'solicitud' => $solicitud,
            'renglones' => $renglones,
            // Total con signo sobre TODOS los renglones congelados (las NC restan).
            'total' => $this->totalDeRenglones($solicitud),
            'descargas' => $registro->paginado($solicitud, self::DESCARGAS_POR_PAGINA, 'descargas_page'),
            'descargasRegistradas' => $registro->cantidad($solicitud),
            'correos' => $solicitud->correos()
                ->orderByDesc('fecha_mensaje')->orderByDesc('id')
                ->get(['id', 'cobro_solicitud_id', 'tipo', 'asunto', 'fecha_mensaje', 'estado', 'motivo', 'referencia_calleja', 'fecha_programada_pago']),
            'ajustes' => CobroAjuste::where('cobro_solicitud_id', $solicitud->id)->with('notaCredito')->orderBy('id')->get(),
            // NC que respaldaron a cada CCF, congeladas al crearla, con su lote y su carga.
            'notasCongeladas' => $solicitud->notas()
                ->with([
                    'documento:id,numero_control',
                    'dte:id,numero_control,total_pagar,estado',
                    'dte.albaran:id,dte_id,numero_canonico,tipo_codigo',
                    'exportacion:id,referencia,presentada_en,presentada_por,referencia_portal',
                    'exportacion.presentadaPor:id,name',
                ])
                ->orderBy('cobro_documento_id')
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * Misma cuenta que {@see CobroSolicitud::total()} —importe congelado del renglón, las
     * NC restan y un renglón sin documento suma—, pero sin hidratar renglones ni
     * documentos: una sola consulta que trae solo importe y tipo, y la suma se hace con
     * aritmética decimal exacta ({@see Dinero}), no con el SUM en coma flotante del motor.
     */
    private function totalDeRenglones(CobroSolicitud $solicitud): string
    {
        $filas = CobroSolicitudItem::query()
            ->where('cobro_solicitud_items.cobro_solicitud_id', $solicitud->id)
            ->leftJoin('cobro_documentos', 'cobro_documentos.id', '=', 'cobro_solicitud_items.cobro_documento_id')
            ->toBase()
            ->get(['cobro_solicitud_items.monto', 'cobro_documentos.tipo_dte']);

        $total = '0';
        foreach ($filas as $fila) {
            $monto = (string) ($fila->monto ?? '0');
            $total = $fila->tipo_dte === '05'
                ? Dinero::restar($total, $monto)
                : Dinero::sumar($total, $monto);
        }

        return Dinero::redondear($total);
    }

    /**
     * Notas de crédito VINCULADAS al CCF, por el único vínculo persistido y seguro:
     * `dtes.dte_relacionado_id` del propio DTE ({@see Dte::notas()}). Nunca por OC,
     * importe, fecha ni texto. Solo lectura.
     *
     * Se listan TODAS las no eliminadas, en cualquier estado (borrador, rechazada,
     * invalidada…), por trazabilidad: solo las aceptadas realmente por Hacienda son NC
     * fiscales vigentes, y la vista dice el estado de cada una. Las borradas (soft delete)
     * no aparecen.
     * Albarán propio y formato de NC van precargados: dos consultas más, no una por nota.
     *
     * @return array{aplica: bool, lista: Collection<int, Dte>, total: int}
     */
    private function notasDelCcf(CobroDocumento $documento): array
    {
        $dte = $documento->dte;

        if ($documento->tipo_dte !== '03' || $dte === null) {
            return ['aplica' => false, 'lista' => collect(), 'total' => 0];
        }

        $consulta = $dte->notas()->where('tipo_dte', '05');

        return [
            'aplica' => true,
            'total' => (clone $consulta)->count(),
            'lista' => $consulta
                ->with([
                    'albaran:id,dte_id,numero_canonico,tipo_codigo,fecha,total',
                    'exportacionItem.exportacion:id,referencia,estado,archivo_nombre,created_at',
                ])
                ->orderBy('fecha_emision')
                ->orderBy('id')
                ->limit(self::NOTAS_EN_FICHA)
                ->get(),
        ];
    }

    /**
     * Da de alta en el seguimiento los CCF/NC aceptados que todavía no estaban.
     *
     * Es idempotente y no pisa nada: correrla dos veces no cambia un solo estado.
     */
    public function sincronizar(Request $request, Cliente $cliente): RedirectResponse
    {
        $resumen = $this->alta->sincronizar($cliente);
        $historicos = $this->alta->marcarRevisionHistorica($cliente);

        return $this->volver($cliente)->with('status', sprintf(
            'Seguimiento actualizado: %d documento(s) nuevo(s), %d incorporado(s) a mano que ahora tienen su DTE, '
                .'%d sin cambio. %d quedaron marcados para revisión histórica.',
            $resumen['creados'],
            $resumen['adoptados'],
            $resumen['sin_cambio'],
            $resumen['revision_historica'] + $historicos,
        ));
    }

    /**
     * AUDITA la vinculación de albaranes sin escribir nada, y la aplica solo si se pide
     * expresamente.
     *
     * El paso en seco es obligatorio en la práctica: es donde se ve, sobre los datos
     * reales, cuántos vínculos saldrían únicos y cuántos ambiguos antes de dejar que el
     * sistema los escriba.
     */
    public function auditarVinculacion(Request $request, Cliente $cliente): View|RedirectResponse
    {
        $documentos = CobroDocumento::deCliente($cliente->id)
            ->where('tipo_dte', '03')
            ->whereDoesntHave('dte', fn ($d) => $d->where('estado', EstadoDte::Invalidado->value))
            ->whereNull('ppq_albaran_id')
            ->with('dte:id,numero_orden_compra,estado')
            ->porAntiguedad()
            ->limit(500)
            ->get();

        $auditoria = $this->vinculador->auditarLote($documentos);

        // Aplicar escribe vínculos: solo por POST (ruta con ppq.gestionar y CSRF). Un GET,
        // aunque traiga `aplicar`, es siempre la auditoría en seco.
        if (! $request->isMethod('post') || ! $request->boolean('aplicar')) {
            return view('cobros.vinculacion', [
                'cliente' => $cliente,
                'auditoria' => $auditoria,
            ]);
        }

        $aplicados = 0;
        foreach ($documentos as $documento) {
            $estado = $this->vinculador->aplicar($documento, $request->user());
            $aplicados += $estado === EstadoVinculacionAlbaran::Vinculado ? 1 : 0;
        }

        return $this->volver($cliente)->with('status', sprintf(
            'Vinculación aplicada: %d albarán(es) vinculado(s); %d quedaron para revisar y %d sin albarán. '
                .'Los ambiguos no se vincularon a ninguno.',
            $aplicados,
            $auditoria['resumen']['revisar'],
            $auditoria['resumen']['sin_albaran'],
        ));
    }

    /** Vínculo elegido a mano entre los candidatos. */
    public function vincularManual(Request $request, CobroDocumento $documento): RedirectResponse
    {
        $datos = $request->validate([
            'ppq_albaran_id' => ['required', 'integer', 'exists:ppq_albaranes,id'],
            'nota' => ['required', 'string', 'max:255'],
        ]);

        $albaran = PpqAlbaran::findOrFail($datos['ppq_albaran_id']);

        $this->vinculador->vincularAMano($documento, $albaran, $request->user(), $datos['nota'] ?? null);

        return back()->with('status', "Albarán {$albaran->numero_albaran} vinculado a mano y registrado.");
    }

    /**
     * Guarda nuestra observación sobre un documento.
     *
     * Escribe SOLO esa columna: el estado de presentación y el de pago no se tocan, que es
     * justo lo que se pidió que no se perdiera.
     */
    public function observacion(Request $request, CobroDocumento $documento): RedirectResponse
    {
        $datos = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $documento->forceFill(['observaciones' => $datos['observaciones'] ?: null])->save();

        return back()->with('status', 'Observación guardada. Los estados de presentación y pago no cambiaron.');
    }

    /**
     * Resuelve un pago que quedó EN REVISIÓN porque otro archivo ya había informado uno
     * sobre el mismo documento.
     *
     * Las dos salidas son legítimas y por eso las dos exigen motivo: «era una repetición»
     * (se descarta) o «era un abono más» (se aplica y suma). El sistema no elige por nadie
     * porque desde el archivo las dos cosas se ven igual.
     */
    public function resolverPago(Request $request, CobroDocumento $documento, CobroEvento $evento): RedirectResponse
    {
        abort_unless($evento->cobro_documento_id === $documento->id, 404);

        $datos = $request->validate([
            'decision' => ['required', 'in:aplicado,descartado'],
            'motivo' => ['required', 'string', 'max:255'],
        ], [], ['motivo' => 'motivo de la decisión']);

        if ($evento->estado !== EstadoEventoCobro::EnRevision) {
            return back()->with('error', 'Ese pago ya estaba resuelto: figura como '.$evento->estado->label().'.');
        }

        $evento->resolver(EstadoEventoCobro::from($datos['decision']), $request->user(), $datos['motivo']);
        $documento->recalcularPago();

        return back()->with('status', $datos['decision'] === 'aplicado'
            ? 'Pago aplicado: ahora cuenta en el importe cobrado.'
            : 'Pago descartado: no cuenta, y queda registrado por qué.');
    }

    /** Marca como revisado un documento señalado para revisión histórica. */
    public function revisadoHistorico(Request $request, CobroDocumento $documento): RedirectResponse
    {
        $datos = $request->validate([
            'nota' => ['required', 'string', 'max:255'],
            'evidencia' => ['required', 'string', 'max:500'],
            'decision' => ['required', 'in:habilitar_presentacion,mantener_bloqueo'],
        ], [], ['nota' => 'conclusión de la revisión']);

        app(RevisionHistoricaService::class)->resolver($documento, $request->user(), $datos['decision'], $datos['nota'], $datos['evidencia']);

        return back()->with('status', $datos['decision'] === 'habilitar_presentacion'
            ? 'Revisión documentada: se confirmó que no se presentó ni se cobró. Se conservó la evidencia.'
            : 'Conclusión guardada. El documento continúa bloqueado para nuevas solicitudes.');
    }

    // ──────────────────────────────── solicitudes ────────────────────────────────

    /**
     * VISTA PREVIA de solo lectura de la solicitud que se armaría con los CCF marcados en la
     * página de la bandeja que se estaba viendo. No crea solicitud ni archivo, no presenta y
     * no toca pagos: solo recuerda en la sesión QUÉ se mostró, para que la confirmación
     * prepare exactamente eso y nada más.
     *
     * Los filtros y la página llegan en la URL, igual que en la bandeja: con ellos se
     * rehace la página y se rechaza cualquier id que no esté en ella (manipulado, de otra
     * página o de otro cliente).
     */
    public function previaSolicitud(Request $request, Cliente $cliente): View|RedirectResponse
    {
        $filtros = $this->filtros($request);
        $pagina = max(1, $request->integer('page', 1));
        $volver = route('cobros.index', ['cliente_id' => $cliente->id]
            + array_filter($filtros, 'filled')
            + ($pagina > 1 ? ['page' => $pagina] : []));

        try {
            $this->verificarClienteDeCobros($cliente);
            $previa = $this->solicitudes->previsualizar($cliente, $this->seleccionDePagina($request, $cliente, $filtros, $pagina));
        } catch (ValidationException $e) {
            return redirect()->to($volver)->withErrors($e->errors());
        }

        // Una sola vista previa vigente por cliente: la última reemplaza a la anterior.
        $token = Str::random(40);
        $request->session()->put(self::SESION_PREVIA.'.'.$cliente->id, [
            'token' => $token,
            'user_id' => $request->user()?->id,
            'documentos' => $previa['documentos'],
            'huella' => $previa['huella'],
            'volver' => $volver,
        ]);

        return view('cobros.solicitud-previa', [
            'cliente' => $cliente,
            'filas' => $previa['filas'],
            'total' => $previa['total'],
            'columnas' => ExportadorSolicitudCargaMasivaV1::columnas(),
            'token' => $token,
            'volver' => $volver,
            // NC de cada CCF y lo que falta para poder preparar el quedan.
            'notas' => $previa['notas'],
            'bloqueos' => NotasDelQuedan::bloqueos($previa['notas']),
        ]);
    }

    /**
     * Prepara el archivo de NC de los CCF de la vista previa: solo sus NC aceptadas que aún
     * no viajaron en ningún lote. Las notas salen de la selección guardada en la sesión, no
     * del formulario. La vista previa queda usada: tras cargar las NC al portal se vuelve a
     * mirar para preparar el quedan.
     */
    public function prepararNotas(Request $request, Cliente $cliente): RedirectResponse
    {
        $previa = $this->previaVigente($request, $cliente);

        if ($previa === null) {
            return $this->volver($cliente)->withErrors([
                'documentos' => 'No se preparó nada: las notas se preparan desde la vista previa vigente de estos '
                    .'CCF, y esta no corresponde a la última (vencida, ya usada o reemplazada por otra). '
                    .'Vuelva a marcar los CCF y revise la vista previa.',
            ]);
        }

        $request->session()->forget(self::SESION_PREVIA.'.'.$cliente->id);

        try {
            $this->verificarClienteDeCobros($cliente);
            $lote = $this->solicitudes->prepararNotas($cliente, $previa['documentos'], $request->user(), $previa['huella']);
        } catch (ValidationException $e) {
            return redirect()->to($previa['volver'])->withErrors($e->errors());
        }

        return redirect()->route('ppq.nc-exportaciones.show', $lote)->with('status', sprintf(
            'Archivo de NC %s preparado con %d nota(s). Descargalo, subilo al portal de Calleja y registrá acá la '
                .'carga; después volvé a Cobros a preparar el quedan de esos CCF. Descargarlo no lo registra como subido.',
            $lote->referencia,
            $lote->items()->count(),
        ));
    }

    /**
     * Prepara la solicitud que se vio en la vista previa. Solo acepta la ÚLTIMA vista
     * previa del cliente, del mismo usuario, y la prepara con SUS documentos —no con lo que
     * traiga el formulario—. El servicio revalida todo dentro de la transacción y, si los
     * renglones ya no coinciden con los mostrados, no escribe nada.
     */
    public function crearSolicitud(Request $request, Cliente $cliente): RedirectResponse
    {
        $clave = self::SESION_PREVIA.'.'.$cliente->id;
        $previa = $this->previaVigente($request, $cliente);

        if ($previa === null) {
            return $this->volver($cliente)->withErrors([
                'documentos' => 'No se preparó nada: el archivo de quedan se prepara desde su vista previa, y esta '
                    .'confirmación no corresponde a la última (vencida, ya usada o reemplazada por otra). '
                    .'Vuelva a marcar los CCF y revise la vista previa.',
            ]);
        }

        // Una vista previa se confirma una sola vez, salga bien o no.
        $request->session()->forget($clave);

        try {
            $this->verificarClienteDeCobros($cliente);
            $solicitud = $this->solicitudes->crear($cliente, $previa['documentos'], $request->user(), null, $previa['huella']);
        } catch (ValidationException $e) {
            return redirect()->to($previa['volver'])->withErrors($e->errors());
        }

        return $this->volver($cliente)->with('status', sprintf(
            'Solicitud %s generada con %d documento(s). Descargala y subila al portal de Calleja; '
                .'después registrá la presentación acá: descargar el archivo no la presenta.',
            $solicitud->referencia,
            $solicitud->items()->count(),
        ));
    }

    /**
     * Descarga el archivo de una solicitud: la primera vez se genera y archiva; después se
     * entrega la copia archivada, verificada por su huella. Si la copia no se puede
     * entregar, falla antes de contar la descarga. Descargar NO la presenta.
     */
    public function descargarSolicitud(CobroSolicitud $solicitud): BinaryFileResponse|RedirectResponse
    {
        try {
            $archivo = $this->solicitudes->archivo($solicitud);
        } catch (CopiaArchivadaInservibleException $e) {
            // Falla ESPERABLE de la copia guardada: detalle al registro, texto seguro en
            // pantalla. Ni se regenera, ni se cuenta la descarga, ni cambia ningún estado.
            report($e);

            return redirect()
                ->route('cobros.index', ['cliente_id' => $solicitud->cliente_id])
                ->with('error', $e->mensajeUsuario());
        }

        // Contador + entrada en la bitácora de descargas PREPARADAS, juntos y solo ahora que
        // el archivo está verificado. La huella registrada es la del archivo que se sirve.
        try {
            app(RegistroDescargas::class)->registrar(
                $solicitud, $archivo, (string) $solicitud->archivo_nombre, request()->user(), (string) $solicitud->referencia,
            );
        } catch (Throwable $e) {
            @unlink($archivo);

            throw $e;
        }

        // Tipo EXPLÍCITO: el temporal no lleva extensión (así tempnam() no deja un vacío
        // huérfano) y Symfony deduciría el MIME del contenido —un ZIP— en vez de XLSX.
        // Fijarlo acá es más simple y seguro que renombrar temporales.
        return response()->download($archivo, $solicitud->archivo_nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Registro EXPLÍCITO de que una persona subió el archivo al portal. */
    public function presentarSolicitud(Request $request, CobroSolicitud $solicitud): RedirectResponse
    {
        $datos = $request->validate([
            'presentada_en' => ['nullable', 'date'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->solicitudes->registrarPresentacion(
                $solicitud,
                $request->user(),
                filled($datos['presentada_en'] ?? null) ? Carbon::parse($datos['presentada_en']) : null,
                $datos['nota'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', "Presentación de {$solicitud->referencia} registrada.");
    }

    /** Acuse del cliente capturado a mano (cuando no llega o no se pudo leer del correo). */
    public function recibidoSolicitud(Request $request, CobroSolicitud $solicitud): RedirectResponse
    {
        $datos = $request->validate([
            'referencia_calleja' => ['required', 'string', 'max:40'],
            'fecha_programada_pago' => ['nullable', 'date'],
            'recibida_en' => ['nullable', 'date'],
        ], [], ['referencia_calleja' => 'referencia del cliente']);

        $this->solicitudes->registrarRecibido(
            $solicitud,
            trim($datos['referencia_calleja']),
            filled($datos['fecha_programada_pago'] ?? null) ? Carbon::parse($datos['fecha_programada_pago']) : null,
            filled($datos['recibida_en'] ?? null) ? Carbon::parse($datos['recibida_en']) : null,
            $request->user(),
        );

        return back()->with('status', "Acuse registrado para {$solicitud->referencia}.");
    }

    // ──────────────────────────────────── pagos ────────────────────────────────────

    /**
     * Aplica el archivo de pagos del cliente al seguimiento.
     *
     * La fecha del pago se pide aparte: el archivo NO la trae —lo que trae es la fecha del
     * documento— y deducirla de ahí sería fechar mal todos los cobros.
     */
    public function aplicarPagos(
        Request $request,
        Cliente $cliente,
        ConciliacionTxtParser $parser,
        AplicadorPagosTxt $aplicador,
    ): View|RedirectResponse {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
            'fecha_pago' => ['nullable', 'date'],
        ], [], ['archivo' => 'archivo de pagos']);

        // La copia se guarda ANTES de procesar: la evidencia no depende de que el
        // procesamiento salga bien.
        $archivo = ArchivoConciliacion::desdeSubida($request->file('archivo'));

        try {
            $informe = $aplicador->aplicar(
                $cliente,
                $parser->parse($archivo->contenido),
                $archivo,
                $request->user(),
                $request->filled('fecha_pago') ? Carbon::parse($request->date('fecha_pago')) : null,
            );
        } catch (ArchivoConciliacionInconsistenteException $e) {
            return $this->volver($cliente)->with('error', $e->getMessage());
        } catch (ArchivoProveedorInvalidoException $e) {
            return $this->volver($cliente)->with('error', $e->getMessage());
        }

        // El mismo archivo concilia los PPQ que llevan esos documentos: un solo lugar para el TXT.
        $lotes = app(ActualizarPpqConTxt::class)->aplicar($parser->parse($archivo->contenido), $archivo, $request->user());

        (new \Illuminate\Database\Eloquent\Collection(collect($informe['ajustes'])->pluck('ajuste')->all()))->load('notaCredito');

        return view('cobros.pagos', [
            'cliente' => $cliente,
            'informe' => $informe,
            'lotesPpq' => $lotes,
        ]);
    }

    /** Crea un PPQ con los CCF marcados (y sus NC) y abre su ficha. */
    public function crearPpq(Request $request, Cliente $cliente, CrearPpqDesdeSeguimiento $creador): RedirectResponse
    {
        $datos = $request->validate([
            'documentos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PPQ],
            'documentos.*' => ['integer', 'distinct'],
        ], [
            'documentos.required' => 'Marque al menos un CCF entregado para crear el PPQ.',
            'documentos.max' => 'El máximo por PPQ es de 500 CCF. Quite algunos de la selección.',
        ]);

        $avisos = app(ElegibilidadPpqSeguimiento::class)->avisos(collect($datos['documentos']));
        $duplicados = collect($avisos)->whereNotNull('duplicado')->count();
        $aviso = $duplicados > 0 ? " Atención: el lote incluyó {$duplicados} posibles duplicados." : '';
        $lote = $creador->crear($cliente, array_map('intval', $datos['documentos']), $request->user());

        $ccf = $lote->items()->where('tipo_dte', '03');
        $cantidad = (clone $ccf)->count();
        $total = number_format((float) $ccf->sum('monto_dte'), 2);
        $documentos = $lote->items()->count();
        $idsCcf = (clone $ccf)->whereNotNull('dte_id')->pluck('dte_id');
        $posteriores = $lote->items()->where('tipo_dte', '05')
            ->whereHas('dte', fn ($q) => $q->whereNotNull('dte_relacionado_id')->whereNotIn('dte_relacionado_id', $idsCcf))
            ->get(['monto_dte']);
        $mensaje = "PPQ creado con {$cantidad} CCF por \${$total} ({$documentos} documento(s) con sus NC). Descargá el archivo de NC y el de quedan.";
        if ($posteriores->isNotEmpty()) {
            $monto = number_format((float) $posteriores->sum('monto_dte'), 2);
            $mensaje .= " Incluye {$posteriores->count()} NC posteriores a una presentación (\${$monto}).";
        }

        return redirect()->route('ppq.lotes.show', $lote)
            ->with('status', $mensaje.$aviso);
    }

    // ─────────────────────────────────── internos ───────────────────────────────────

    /**
     * La ÚLTIMA vista previa del cliente, si el token del formulario es el suyo y es del
     * mismo usuario. Null en cualquier otro caso.
     *
     * @return array<string, mixed>|null
     */
    private function previaVigente(Request $request, Cliente $cliente): ?array
    {
        $previa = $request->session()->get(self::SESION_PREVIA.'.'.$cliente->id);
        $token = $request->input('previa');

        if (! is_array($previa) || ! is_string($token) || ! hash_equals((string) ($previa['token'] ?? ''), $token)
            || ($previa['user_id'] ?? null) !== $request->user()?->id) {
            return null;
        }

        return $previa;
    }

    /**
     * Solo los clientes que la bandeja muestra (perfil documental activo) preparan quedan.
     *
     * @throws ValidationException
     */
    private function verificarClienteDeCobros(Cliente $cliente): void
    {
        if (! $this->clientes()->contains('id', $cliente->id)) {
            throw ValidationException::withMessages([
                'documentos' => 'No se preparó nada: este cliente no tiene perfil documental activo para cobros.',
            ]);
        }
    }

    /**
     * Los ids marcados, siempre que TODOS estén en la página de la bandeja que se estaba
     * viendo (mismos filtros, misma página, mismo orden). Si la lista cambió entretanto, o
     * alguien agregó un id a mano, se rechaza la selección entera.
     *
     * @param  array<string, string>  $filtros
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function seleccionDePagina(Request $request, Cliente $cliente, array $filtros, int $pagina): array
    {
        $datos = Validator::make($request->all(), [
            'documentos' => ['required', 'array', 'min:1', 'max:'.self::POR_PAGINA],
            'documentos.*' => ['integer', 'distinct'],
        ], [
            'documentos.required' => 'Marque al menos un CCF listo para ver la vista previa del archivo de quedan.',
        ], ['documentos' => 'documentos'])->validate();

        $pedidos = array_values(array_unique(array_map('intval', $datos['documentos'])));

        $enPagina = $this->consulta($cliente, $filtros)
            ->porRecencia()
            ->forPage($pagina, self::POR_PAGINA)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($pedidos, $enPagina) !== []) {
            throw ValidationException::withMessages([
                'documentos' => 'No se preparó nada: la selección incluye documentos que no están en la página que '
                    .'se estaba viendo, o la lista cambió desde que se cargó. Vuelva a marcarlos.',
            ]);
        }

        return $pedidos;
    }

    /** @return Builder<CobroDocumento> */
    private function consulta(Cliente $cliente, array $filtros): Builder
    {
        return $this->consultaSinEtapa($cliente, $filtros)
            ->when(filled($filtros['etapa']), fn ($q) => $this->aplicarEtapa($q, $filtros['etapa']));
    }

    /**
     * La lista es de CCF: sus NC se muestran debajo de cada uno. Solo con «tipo=05» se
     * listan las NC sueltas.
     *
     * @return Builder<CobroDocumento>
     */
    private function consultaSinEtapa(Cliente $cliente, array $filtros): Builder
    {
        return $this->consultaSinMes($cliente, $filtros)
            ->when(filled($filtros['mes']), function ($q) use ($filtros) {
                $inicio = Carbon::createFromFormat('!Y-m', $filtros['mes']);

                return $q->whereBetween('fecha_emision', [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()]);
            });
    }

    /**
     * CCF que sus NC dejan en saldo 0: fuera del Seguimiento. Se calcula una vez por petición
     * porque la bandeja arma muchas consultas (tarjetas, meses, lista).
     *
     * @return array<int, int>
     */
    private function saldadosConNc(Cliente $cliente): array
    {
        return $this->saldadosConNc[$cliente->id] ??= CobroDocumento::idsSaldadosConNc($cliente->id);
    }

    /** @return Builder<CobroDocumento> */
    private function consultaSinMes(Cliente $cliente, array $filtros): Builder
    {
        return CobroDocumento::deCliente($cliente->id)
            ->sinInvalidadosRetirables()
            ->whereKeyNot($this->saldadosConNc($cliente))
            ->where('tipo_dte', $filtros['tipo'] === '05' ? '05' : '03')
            // Fuera los DTE de otro ambiente (pruebas en el servidor real).
            ->when(config('dte.ambiente') === '01', fn ($q) => $q->whereDoesntHave('dte', fn ($d) => $d->where('ambiente', '00')))
            ->when(filled($filtros['presentacion']), fn ($q) => $q->where('presentacion_estado', $filtros['presentacion']))
            ->when(filled($filtros['pago']), fn ($q) => $q->where('pago_estado', $filtros['pago']))
            ->when(filled($filtros['vinculacion']), fn ($q) => $q->where('vinculacion_estado', $filtros['vinculacion']))
            ->when(filled($filtros['revisar']), fn ($q) => $q->where('revisar_historico', true))
            ->when(filled($filtros['pago_revision']), fn ($q) => $q->conPagosEnRevision())
            ->when(filled($filtros['desde']), fn ($q) => $q->whereDate('fecha_emision', '>=', $filtros['desde']))
            ->when(filled($filtros['hasta']), fn ($q) => $q->whereDate('fecha_emision', '<=', $filtros['hasta']))
            ->when(filled($filtros['sala']), fn ($q) => $q->whereHas('albaran', fn ($a) => $a->where('sala_codigo', $filtros['sala'])))
            ->when(filled($filtros['q']), fn ($q) => $q->where(fn ($w) => $w
                ->where('numero_control', 'like', '%'.$filtros['q'].'%')
                ->orWhere('codigo_generacion', 'like', '%'.$filtros['q'].'%')
                ->orWhereHas('albaran', fn ($a) => $a->where('numero_albaran', 'like', '%'.$filtros['q'].'%'))));
    }

    /**
     * Cuántos hay en cada situación. Se calculan SIN los filtros a propósito: son el mapa
     * del total, y si cambiaran con el filtro dejarían de servir para saber qué falta.
     *
     * @return array<string, mixed>
     */
    private function contadores(Cliente $cliente): array
    {
        $base = fn () => CobroDocumento::deCliente($cliente->id)->sinInvalidadosRetirables()
            ->whereKeyNot($this->saldadosConNc($cliente));

        $presentacion = [];
        foreach (EstadoPresentacionCobro::cases() as $estado) {
            $presentacion[$estado->value] = (clone $base())->where('presentacion_estado', $estado->value)->count();
        }

        $pago = [];
        foreach (EstadoPagoCobro::cases() as $estado) {
            $pago[$estado->value] = (clone $base())->where('pago_estado', $estado->value)->count();
        }

        return [
            'total' => $base()->count(),
            'presentacion' => $presentacion,
            'pago' => $pago,
            'revisar_vinculacion' => $base()->where('vinculacion_estado', EstadoVinculacionAlbaran::Revisar->value)->count(),
            'sin_albaran' => $base()->where('vinculacion_estado', EstadoVinculacionAlbaran::SinAlbaran->value)->count(),
            'revisar_historico' => $base()->where('revisar_historico', true)->count(),
            // Pagos informados que NO cuentan hasta que alguien diga si son repetición o
            // abono nuevo. Se cuentan aparte porque mueven el saldo real del cliente.
            'pagos_en_revision' => (clone $base())->conPagosEnRevision()->count(),
            'saldo_pendiente' => (clone $base())->pendienteDeCobro()->sum('monto'),
        ];
    }

    /** @param Builder<CobroDocumento> $q */
    private function aplicarEtapa(Builder $q, string $etapa): Builder
    {
        $pendiente = EstadoPagoCobro::Pendiente->value;
        $enCurso = [EstadoPresentacionCobro::Preparada->value, EstadoPresentacionCobro::Presentada->value, EstadoPresentacionCobro::Recibida->value];

        return match ($etapa) {
            // Sin albarán en el correo: el pedido no se ha entregado en la sala.
            'no_entregados' => $q->where('pago_estado', $pendiente)->whereNotIn('presentacion_estado', $enCurso)->whereNull('ppq_albaran_id'),
            'listos' => $q->where('pago_estado', $pendiente)->whereNotIn('presentacion_estado', $enCurso)->whereNotNull('ppq_albaran_id'),
            'presentados' => $q->where('pago_estado', $pendiente)->whereIn('presentacion_estado', $enCurso),
            // Pagado es solo lo que cuadra con el CCF menos sus NC; un faltante o un cobro de
            // más va aparte para que nadie lo dé por cobrado (issue #14).
            'pagados' => $q->where('pago_estado', EstadoPagoCobro::Pagado->value),
            'diferencias' => $q->whereIn('pago_estado', [EstadoPagoCobro::Parcial->value, EstadoPagoCobro::Diferencia->value]),
            default => $q,
        };
    }

    /**
     * Cuántos CCF hay en cada etapa, dentro del mes elegido.
     *
     * @return array<string, int>
     */
    private function conteoEtapas(Cliente $cliente, array $filtros): array
    {
        $conteo = ['' => $this->consultaSinEtapa($cliente, $filtros)->count()];
        foreach (array_keys(self::ETAPAS) as $etapa) {
            $conteo[$etapa] = $this->aplicarEtapa($this->consultaSinEtapa($cliente, $filtros), $etapa)->count();
        }

        return $conteo;
    }

    /**
     * Meses con CCF (más reciente primero): total y cuántos siguen por presentar.
     *
     * @return array<int, array{mes: string, etiqueta: string, total: int, por_presentar: int}>
     */
    private function meses(Cliente $cliente, array $filtros): array
    {
        $porMes = fn (Builder $q) => $q->whereNotNull('fecha_emision')->pluck('fecha_emision')
            ->countBy(fn ($f) => Carbon::parse($f)->format('Y-m'));

        $porPresentar = $porMes($this->aplicarEtapa($this->consultaSinMes($cliente, $filtros), 'listos'));

        return $porMes($this->consultaSinMes($cliente, $filtros))
            ->sortKeysDesc()
            ->map(fn ($total, $mes) => [
                'mes' => $mes,
                'etiqueta' => ucfirst(Carbon::createFromFormat('!Y-m', $mes)->locale('es')->translatedFormat('F Y')),
                'total' => $total,
                'por_presentar' => (int) ($porPresentar[$mes] ?? 0),
            ])
            ->values()->all();
    }

    /**
     * NC emitidas contra cada CCF de la página (por su DTE), en una sola consulta.
     *
     * @param  Collection<int, CobroDocumento>  $documentos
     * @return Collection<int, Collection<int, Dte>> por dte_id del CCF
     */
    private function notasPorCcf(Collection $documentos): Collection
    {
        $ids = $documentos->pluck('dte_id')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        return Dte::query()
            ->whereIn('dte_relacionado_id', $ids)
            ->where('tipo_dte', '05')
            // Solo notas emitidas de verdad: aceptadas, o invalidadas después de aceptarse.
            ->whereNotNull('sello_recepcion')
            ->whereIn('estado', [EstadoDte::Aceptado->value, EstadoDte::Invalidado->value])
            ->with('albaran:id,dte_id,numero_canonico,tipo_codigo')
            ->orderBy('fecha_emision')
            ->get(['id', 'dte_relacionado_id', 'numero_control', 'fecha_emision', 'total_pagar', 'estado', 'sello_recepcion'])
            ->groupBy('dte_relacionado_id');
    }

    /** @return array<int, string> */
    private function salas(Cliente $cliente): array
    {
        return CobroDocumento::deCliente($cliente->id)
            ->whereNotNull('ppq_albaran_id')
            ->with('albaran:id,sala_codigo')
            ->get(['id', 'ppq_albaran_id'])
            ->map(fn (CobroDocumento $d) => $d->albaran?->sala_codigo)
            ->filter()->unique()->sort()->values()->all();
    }

    /**
     * Historial de solicitudes del cliente, acotado por los filtros que traigan valor.
     *
     * @param  array<string, string>  $filtros
     * @return Builder<CobroSolicitud>
     */
    private function consultaSolicitudes(Cliente $cliente, array $filtros): Builder
    {
        return CobroSolicitud::where('cliente_id', $cliente->id)
            ->when(filled($filtros['sol_estado']), fn ($q) => $q->where('estado', $filtros['sol_estado']))
            ->when(filled($filtros['sol_desde']), fn ($q) => $q->whereDate('created_at', '>=', $filtros['sol_desde']))
            ->when(filled($filtros['sol_hasta']), fn ($q) => $q->whereDate('created_at', '<=', $filtros['sol_hasta']))
            ->when(filled($filtros['sol_q']), fn ($q) => $q->where(fn ($w) => $w
                ->where('referencia', 'like', '%'.$filtros['sol_q'].'%')
                ->orWhere('archivo_nombre', 'like', '%'.$filtros['sol_q'].'%')
                ->orWhere('referencia_calleja', 'like', '%'.$filtros['sol_q'].'%')));
    }

    /** @return array<string, string> */
    private function filtros(Request $request): array
    {
        $filtros = [];
        foreach (self::FILTROS as $clave) {
            $filtros[$clave] = $this->texto($request, $clave);
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filtros['mes'])) {
            $filtros['mes'] = '';
        }
        if (! array_key_exists($filtros['etapa'], self::ETAPAS)) {
            $filtros['etapa'] = '';
        }

        return $filtros;
    }

    /**
     * Filtros del historial de solicitudes. Un estado o una fecha que no sean válidos se
     * ignoran (se ve todo), en vez de filtrar por algo que no existe.
     *
     * @return array<string, string>
     */
    private function filtrosSolicitud(Request $request): array
    {
        $filtros = [];
        foreach (self::FILTROS_SOLICITUD as $clave) {
            $filtros[$clave] = $this->texto($request, $clave);
        }

        if (EstadoSolicitudCobro::tryFrom($filtros['sol_estado']) === null) {
            $filtros['sol_estado'] = '';
        }
        foreach (['sol_desde', 'sol_hasta'] as $clave) {
            if ($filtros[$clave] !== '' && ! $this->esFecha($filtros[$clave])) {
                $filtros[$clave] = '';
            }
        }
        $filtros['sol_q'] = mb_substr($filtros['sol_q'], 0, 80);

        return $filtros;
    }

    /** Valor de texto de un parámetro GET; un arreglo u otro no escalar vale vacío, no 500. */
    private function texto(Request $request, string $clave): string
    {
        $valor = $request->query($clave, '');

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function esFecha(string $valor): bool
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $fecha !== false && $fecha->format('Y-m-d') === $valor;
    }

    /** @return Collection<int, Cliente> */
    private function clientes(): Collection
    {
        return Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'nombre_comercial']);
    }

    /** @param Collection<int, Cliente> $clientes */
    private function clienteElegido(Request $request, Collection $clientes): ?Cliente
    {
        $valor = $request->query('cliente_id');
        if (is_scalar($valor) && trim((string) $valor) !== '') {
            return $clientes->firstWhere('id', $request->integer('cliente_id'));
        }

        return $clientes->count() === 1 ? $clientes->first() : null;
    }

    private function volver(Cliente $cliente): RedirectResponse
    {
        return redirect()->route('cobros.index', ['cliente_id' => $cliente->id]);
    }
}
