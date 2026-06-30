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
            'items.albaran:id,numero_albaran,fecha_albaran,monto_albaran,sala_codigo',
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
     * Concilia el lote contra el TXT de pagos de Calleja: marca cada CCF como PAGADO/CONCILIADO
     * solo si aparece en el TXT como CF (y las NC como APLICADA si aparecen como NC), guardando
     * fecha y monto del TXT. Devuelve un resumen interno. NO modifica el Excel oficial de Calleja.
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

        $contenido = (string) file_get_contents($request->file('archivo')->getRealPath());
        $filas = $parser->parse($contenido);
        $reporte = $conciliador->conciliar($lote, $filas);

        return view('ppq.lotes.conciliacion', [
            'lote' => $lote,
            'reporte' => $reporte,
            'archivo' => $request->file('archivo')->getClientOriginalName(),
            'totalFilas' => count($filas),
        ]);
    }
}
