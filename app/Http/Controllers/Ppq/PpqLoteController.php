<?php

namespace App\Http\Controllers\Ppq;

use App\Enums\EstadoPpq;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ppq\PpqLoteRequest;
use App\Models\Cliente;
use App\Models\PpqLote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lotes de Prontos Pagos (PPQ). Gestión de los lotes de cobro; el detalle muestra
 * sus CCF/NC y permite agregar/quitar items. No toca la emisión de DTE.
 */
class PpqLoteController extends Controller
{
    public function index(Request $request): View
    {
        $lotes = PpqLote::query()
            ->withCount('items')
            // Total NETO del lote: CCF suma, NC (tipo 05) resta.
            ->addSelect(['total_dte' => \App\Models\PpqItem::query()
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dte = '05' THEN -monto_dte ELSE monto_dte END), 0)")
                ->whereColumn('ppq_lote_id', 'ppq_lotes.id')])
            ->with('cliente:id,nombre,nombre_comercial')
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('ppq.lotes.index', [
            'lotes' => $lotes,
            'estados' => EstadoPpq::opciones(),
        ]);
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
        $lote = PpqLote::create($request->validated() + ['user_id' => $request->user()->id]);

        return redirect()
            ->route('ppq.lotes.show', $lote)
            ->with('status', 'Lote PPQ creado. Agregá los CCF/NC desde la búsqueda.');
    }

    public function show(PpqLote $lote): View
    {
        $lote->load([
            'cliente:id,nombre,nombre_comercial',
            'items.dte:id,tipo_dte,numero_control,codigo_generacion,sello_recepcion,fecha_emision,total_pagar,numero_orden_compra,cliente_sucursal_id',
            'items.dte.clienteSucursal:id,nombre,codigo',
            'items.albaran:id,numero_albaran,tipo_codigo,fecha_albaran,monto_albaran,sala_codigo',
            // Bitácora de conciliación: de dónde salió cada pago y quién lo corrigió.
            'conciliaciones.usuario:id,name',
        ]);

        return view('ppq.lotes.show', ['lote' => $lote]);
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

    public function destroy(PpqLote $lote): RedirectResponse
    {
        $lote->delete();

        return redirect()
            ->route('ppq.lotes.index')
            ->with('status', 'Lote PPQ eliminado.');
    }

    public function excel(PpqLote $lote, \App\Services\Ppq\ExcelCallejaExporter $exporter): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        if ($lote->items()->count() === 0) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', 'El lote no tiene documentos para exportar.');
        }
        $ruta = $exporter->generar($lote);

        return response()->download($ruta, $exporter->nombreArchivo($lote))->deleteFileAfterSend();
    }

    /**
     * Concilia el lote contra el TXT de pagos del cliente: marca como PAGADO cada CCF que
     * aparece en el archivo como CF (y como APLICADA cada NC que aparece como NC),
     * guardando la fecha y el monto que reporta el archivo.
     *
     * Lo que NO hace, y es el cambio importante: no toca los renglones que el archivo no
     * menciona. Antes se los limpiaba, así que un TXT parcial borraba pagos ya
     * registrados. Ver {@see \App\Services\Ppq\ConciliadorPpq}.
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
        \App\Services\Ppq\ConciliacionTxtParser $parser,
        \App\Services\Ppq\ConciliadorPpq $conciliador,
    ): View|RedirectResponse {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
        ], [], ['archivo' => 'archivo de pagos']);

        if ($lote->items()->count() === 0) {
            return redirect()->route('ppq.lotes.show', $lote)->with('error', 'El lote no tiene documentos para conciliar.');
        }

        // Se guarda la copia y se calcula la huella antes de tocar nada: la evidencia no
        // depende de que el procesamiento salga bien.
        $archivo = \App\Services\Ppq\ArchivoConciliacion::desdeSubida($request->file('archivo'));
        $filas = $parser->parse($archivo->contenido);

        try {
            $reporte = $conciliador->conciliar($lote, $filas, $request->user(), $archivo);
        } catch (\App\Exceptions\Ppq\ConciliacionYaProcesadaException $e) {
            // No es un error del usuario ni un fallo: es la respuesta correcta a subir dos
            // veces el mismo archivo. Se dice cuándo se procesó y no se cambia nada.
            return redirect()->route('ppq.lotes.show', $lote)->with('status', $e->getMessage());
        } catch (\App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException $e) {
            // El archivo se contradice a sí mismo. No se aplicó nada: el mensaje dice qué
            // documento está repetido y con qué valores, para poder reclamarlo al cliente.
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
        \App\Models\PpqItem $item,
        \App\Services\Ppq\ReversionConciliacion $reversion,
    ): RedirectResponse {
        abort_unless($item->ppq_lote_id === $lote->id, 404);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ], [], ['motivo' => 'motivo']);

        $reversion->revertir($item, $datos['motivo'], $request->user());

        return redirect()
            ->route('ppq.lotes.show', $lote)
            ->with('status', 'Se quitó el cobro registrado del documento. Vuelve a contar como pendiente y quedó anotado quién lo hizo y por qué.');
    }
}
