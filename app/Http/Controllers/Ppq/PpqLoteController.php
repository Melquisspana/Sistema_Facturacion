<?php

namespace App\Http\Controllers\Ppq;

use App\Enums\EstadoDte;
use App\Enums\EstadoPpq;
use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Exceptions\Ppq\ArchivoQuedanIncompletoException;
use App\Exceptions\Ppq\ConciliacionYaProcesadaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppq\PpqLoteRequest;
use App\Models\Cliente;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Services\Cobros\ReporteCasoCalleja;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ConciliadorPpq;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Services\Ppq\FichaLotePpq;
use App\Services\Ppq\NcExportacionService;
use App\Services\Ppq\QuedanCallejaExporter;
use App\Services\Ppq\ReversionConciliacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lotes de Prontos Pagos (PPQ). Gestión de los lotes de cobro; el detalle muestra
 * sus CCF/NC y permite agregar/quitar items. No toca la emisión de DTE.
 */
class PpqLoteController extends Controller
{
    /**
     * Historial de lotes con filtros OPCIONALES: cliente, estado, rango de fecha del lote y
     * un único campo de texto para la referencia o el número de lote (`#12` o `12`).
     *
     * Un filtro inválido (estado inexistente, fecha ilegible, un valor que no es texto) se
     * ignora en vez de romper la pantalla. Orden: fecha del lote y luego id, los más
     * recientes primero; el id deshace los empates de fecha, así que entre páginas ningún
     * lote se repite ni se pierde.
     */
    public function index(Request $request): View
    {
        $filtros = $this->filtrosHistorial($request);

        $lotes = PpqLote::query()
            ->withCount('items')
            // Total NETO del lote: CCF suma, NC (tipo 05) resta.
            ->addSelect(['total_dte' => PpqItem::query()
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dte = '05' THEN -monto_dte ELSE monto_dte END), 0)")
                ->whereColumn('ppq_lote_id', 'ppq_lotes.id')])
            ->with('cliente:id,nombre,nombre_comercial')
            ->when($filtros['cliente_id'] !== '', fn ($q) => $q->where('cliente_id', (int) $filtros['cliente_id']))
            ->when($filtros['estado'] !== '', fn ($q) => $q->where('estado', $filtros['estado']))
            ->when($filtros['desde'] !== '', fn ($q) => $q->whereDate('fecha', '>=', $filtros['desde']))
            ->when($filtros['hasta'] !== '', fn ($q) => $q->whereDate('fecha', '<=', $filtros['hasta']))
            ->when($filtros['q'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['q'];
                $numero = ltrim($texto, '#');

                $q->where(function ($w) use ($texto, $numero) {
                    $w->where('referencia', 'like', '%'.$texto.'%');
                    // «12» o «#12»: también el número de lote, exacto.
                    if ($numero !== '' && ctype_digit($numero)) {
                        $w->orWhere('id', (int) $numero);
                    }
                });
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('ppq.lotes.index', [
            'lotes' => $lotes,
            'estados' => EstadoPpq::opciones(),
            'filtros' => $filtros,
            'hayFiltros' => collect($filtros)->contains(fn ($v) => $v !== ''),
            // Solo clientes que tienen lotes: ofrecer otros daría listas vacías.
            'clientes' => Cliente::query()
                ->whereIn('id', PpqLote::query()->whereNotNull('cliente_id')->select('cliente_id'))
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }

    /**
     * Filtros normalizados del historial; los inválidos quedan vacíos (se ignoran).
     *
     * @return array{cliente_id: string, estado: string, desde: string, hasta: string, q: string}
     */
    private function filtrosHistorial(Request $request): array
    {
        // Solo valores ESCALARES: `?q[]=x` llega como arreglo y convertirlo a texto rompería
        // la pantalla. Un arreglo (u otra cosa) cuenta como filtro vacío.
        $texto = function (string $clave) use ($request): string {
            $valor = $request->input($clave, '');

            return is_string($valor) || is_int($valor) || is_float($valor) ? trim((string) $valor) : '';
        };

        $fecha = function (string $clave) use ($texto): string {
            $valor = $texto($clave);

            // Solo Y-m-d reales: sin desbordes («2026-02-30» no pasa a marzo).
            $leida = $valor === '' ? null : rescue(fn () => Carbon::createFromFormat('!Y-m-d', $valor), null, false);

            return $leida !== null && $leida !== false && $leida->format('Y-m-d') === $valor ? $valor : '';
        };

        $cliente = $texto('cliente_id');
        $estado = $texto('estado');

        return [
            'cliente_id' => ctype_digit($cliente) ? $cliente : '',
            'estado' => EstadoPpq::tryFrom($estado) !== null ? $estado : '',
            'desde' => $fecha('desde'),
            'hasta' => $fecha('hasta'),
            'q' => mb_substr($texto('q'), 0, 60),
        ];
    }

    public function create(): View
    {
        return view('ppq.lotes.create', [
            'clientes' => Cliente::orderBy('nombre')->get(['id', 'nombre']),
            'estados' => EstadoPpq::opciones(),
            'clienteDefault' => config('ppq.cliente_default_id'),
        ]);
    }

    public function store(PpqLoteRequest $request): RedirectResponse
    {
        // El módulo es de Calleja: el cliente y el estado inicial no se preguntan.
        $lote = PpqLote::create($request->validated() + [
            'user_id' => $request->user()->id,
            'estado' => EstadoPpq::Borrador->value,
            'cliente_id' => config('ppq.cliente_default_id')
                ?? Cliente::whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))->value('id'),
        ]);

        return redirect()
            ->route('ppq.lotes.show', $lote)
            ->with('status', 'Lote PPQ creado. Agregá los CCF/NC desde la búsqueda.');
    }

    /**
     * Ficha del lote. Los documentos se muestran por páginas de 25, los más recientes primero;
     * los totales y conteos son del lote COMPLETO. Solo se hidratan con relaciones los items
     * de la página visible ({@see FichaLotePpq}).
     */
    public function show(PpqLote $lote, FichaLotePpq $ficha): View
    {
        $lote->load([
            'cliente:id,nombre,nombre_comercial',
            // Bitácora de conciliación: de dónde salió cada pago y quién lo corrigió.
            'conciliaciones.usuario:id,name',
        ]);

        $filas = $ficha->filas($lote);

        return view('ppq.lotes.show', [
            'lote' => $lote,
            'resumen' => $ficha->resumen($filas),
            'items' => $ficha->pagina($ficha->idsRecientesPrimero($filas)),
        ]);
    }

    public function edit(PpqLote $lote): View
    {
        return view('ppq.lotes.edit', [
            'lote' => $lote,
            'clientes' => Cliente::orderBy('nombre')->get(['id', 'nombre']),
            'estados' => EstadoPpq::opciones(),
        ]);
    }

    public function update(PpqLoteRequest $request, PpqLote $lote): RedirectResponse
    {
        $lote->update($request->validated());

        return redirect()
            ->route('ppq.lotes.show', $lote)
            ->with('status', 'Lote PPQ actualizado.');
    }

    /**
     * Archivo de NC (formato aceptado por Calleja) con las NC del PPQ que aún no están en
     * ningún formato; si ya lo están todas, descarga el formato donde quedaron.
     */
    public function archivoNc(Request $request, PpqLote $lote, NcExportacionService $exportaciones): RedirectResponse
    {
        $ids = $lote->items()->where('tipo_dte', '05')->whereNotNull('dte_id')
            ->whereHas('dte', fn ($q) => $q->where('estado', EstadoDte::Aceptado->value))
            ->pluck('dte_id')->all();
        if ($ids === []) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', 'Este PPQ no tiene notas de crédito vigentes.');
        }

        $yaExportadas = NcExportacionItem::whereIn('dte_id', $ids)->pluck('nc_exportacion_id', 'dte_id');
        $pendientes = array_values(array_diff($ids, $yaExportadas->keys()->all()));

        if ($pendientes === []) {
            $formatos = $yaExportadas->unique()->values();

            return $formatos->count() === 1
                ? redirect()->route('ppq.nc-exportaciones.descargar', $formatos->first())
                : redirect()->route('ppq.nc-exportaciones.index')->with('status', 'Las NC de este PPQ ya están en los formatos '
                    .NcExportacion::whereIn('id', $formatos)->pluck('referencia')->implode(', ').'.');
        }

        $cliente = $lote->cliente ?? Cliente::whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))->firstOrFail();
        try {
            $formato = $exportaciones->crear($cliente, $pendientes, $request->user());
        } catch (ValidationException $e) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('ppq.nc-exportaciones.descargar', $formato);
    }

    /** Reporte del caso que devuelve el portal: el PPQ queda presentado y lo no tomado vuelve a por presentar. */
    public function reporteCaso(Request $request, PpqLote $lote, ReporteCasoCalleja $reportes): RedirectResponse
    {
        $request->validate([
            'reporte' => ['required', 'file', 'max:5120'],
            'caso' => ['nullable', 'digits_between:1,12'],
        ], [], ['reporte' => 'reporte del caso', 'caso' => 'número de caso']);

        try {
            $reporte = $reportes->leer($request->file('reporte')->getRealPath());
        } catch (\RuntimeException $e) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', $e->getMessage());
        }
        // El número definitivo es el del correo de Calleja; el del archivo puede ser de un intento previo.
        if ($request->filled('caso')) {
            $reporte['caso'] = (string) $request->input('caso');
        }

        $cliente = $lote->cliente ?? Cliente::whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))->firstOrFail();
        $r = $reportes->aplicar($cliente, $reporte, $request->user(), $lote);

        $mensaje = "Caso {$r['caso']}: {$r['recibidos']} documento(s) registrados por Calleja. El PPQ quedó presentado.";
        if ($r['devueltos'] !== []) {
            $mensaje .= ' No tomó y vuelven a por presentar: '
                .collect($r['devueltos'])->map(fn ($d) => $d->correlativoCorto())->implode(', ').'.';
        }

        return redirect()->route('ppq.lotes.show', $lote)->with('status', $mensaje);
    }

    public function destroy(PpqLote $lote): RedirectResponse
    {
        $lote->delete();

        return redirect()
            ->route('ppq.lotes.index')
            ->with('status', 'Lote PPQ eliminado.');
    }

    public function excel(PpqLote $lote, ExcelCallejaExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        if ($lote->items()->count() === 0) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', 'El lote no tiene documentos para exportar.');
        }
        $ruta = $exporter->generar($lote);

        return response()->download($ruta, $exporter->nombreArchivo($lote))->deleteFileAfterSend();
    }

    /**
     * Archivo de carga masiva de «Solicitud de Quedan» del portal de Calleja (5 columnas),
     * armado desde los CCF del lote. Es una LECTURA: no crea CobroSolicitud, no registra
     * presentación ni pago, y no toca el lote ni sus items. Ver {@see QuedanCallejaExporter}.
     */
    public function quedan(PpqLote $lote, QuedanCallejaExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        try {
            $ruta = $exporter->generar($lote);
        } catch (ArchivoQuedanIncompletoException $e) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', $e->getMessage());
        }

        // Tipo EXPLÍCITO: el temporal no lleva extensión (tempnam() sin ".xlsx", como en
        // ExportadorSolicitudCargaMasivaV1) y Symfony deduciría el MIME del contenido.
        return response()->download($ruta, $exporter->nombreArchivo($lote), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Concilia el lote contra el TXT de pagos del cliente: marca como PAGADO cada CCF que
     * aparece en el archivo como CF (y como APLICADA cada NC que aparece como NC),
     * guardando la fecha y el monto que reporta el archivo.
     *
     * Lo que NO hace, y es el cambio importante: no toca los renglones que el archivo no
     * menciona. Antes se los limpiaba, así que un TXT parcial borraba pagos ya
     * registrados. Ver {@see ConciliadorPpq}.
     *
     * El archivo se guarda ANTES de procesar y queda referenciado en la bitácora con su
     * huella: es la prueba de que el cliente reportó esos pagos. Subir dos veces el mismo
     * archivo no vuelve a aplicar nada.
     *
     * NO modifica el Excel oficial del cliente.
     */
    public function conciliar(
        Request $request,
        PpqLote $lote,
        ConciliacionTxtParser $parser,
        ConciliadorPpq $conciliador,
    ): View|RedirectResponse {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
        ], [], ['archivo' => 'archivo de pagos']);

        if ($lote->items()->count() === 0) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', 'El lote no tiene documentos para conciliar.');
        }

        // Se guarda la copia y se calcula la huella antes de tocar nada: la evidencia no
        // depende de que el procesamiento salga bien.
        $archivo = ArchivoConciliacion::desdeSubida($request->file('archivo'));
        $filas = $parser->parse($archivo->contenido);

        try {
            $reporte = $conciliador->conciliar($lote, $filas, $request->user(), $archivo);
        } catch (ConciliacionYaProcesadaException $e) {
            // No es un error del usuario ni un fallo: es la respuesta correcta a subir dos
            // veces el mismo archivo. Se dice cuándo se procesó y no se cambia nada.
            return redirect()->route('ppq.lotes.show', $lote)->with('status', $e->getMessage());
        } catch (ArchivoConciliacionInconsistenteException $e) {
            // El archivo se contradice a sí mismo. No se aplicó nada: el mensaje dice qué
            // documento está repetido y con qué valores, para poder reclamarlo al cliente.
            return redirect()->route('ppq.lotes.show', $lote)->with('error', $e->getMessage());
        } catch (ArchivoProveedorInvalidoException $e) {
            // Alguna fila trae un código de proveedor distinto del esperado. No se aplicó
            // nada: no se puede tratar como pago de este cliente lo que el archivo no dice
            // que sea suyo.
            return redirect()->route('ppq.lotes.show', $lote)->with('error', $e->getMessage());
        }

        return view('ppq.lotes.conciliacion', [
            'lote' => $lote,
            'reporte' => $reporte,
            'archivo' => $archivo->nombre,
            'totalFilas' => count($filas),
        ]);
    }

    /**
     * Quita el cobro registrado de UN renglón del lote. La única forma de deshacer un pago.
     *
     * Va aparte de conciliar —y con su propio permiso— porque son actos distintos: aquel
     * aplica lo que dijo el cliente, este contradice algo que ya se había dado por cobrado.
     * El motivo es obligatorio y queda con el nombre de quien lo pidió.
     */
    public function revertirItem(
        Request $request,
        PpqLote $lote,
        PpqItem $item,
        ReversionConciliacion $reversion,
    ): RedirectResponse {
        abort_unless($item->ppq_lote_id === $lote->id, 404);

        $datos = $request->validate([
            'motivo' => ['nullable', 'string', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        $reversion->revertir($item, $datos['motivo'] ?? null, $request->user());

        // Vuelve a la MISMA página de la ficha desde la que se pidió (solo un entero ≥ 2).
        $pagina = $request->input('page');
        $pagina = is_scalar($pagina) && ctype_digit((string) $pagina) && (int) $pagina > 1 ? (int) $pagina : null;

        return redirect()
            ->route('ppq.lotes.show', array_filter(['lote' => $lote, 'page' => $pagina]))
            ->with('status', 'Se quitó el cobro registrado del documento. Vuelve a contar como pendiente y quedó anotado quién lo hizo y por qué.');
    }
}
