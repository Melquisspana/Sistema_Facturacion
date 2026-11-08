<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\OrdenCompraRecibida;
use App\Services\Dte\DtePdfService;
use App\Services\Dte\PdfConOrdenCompra;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Órdenes de compra recibidas por correo: la lista (para ver las que llegaron y no se
 * facturaron), su PDF y el «PDF + orden» de la ficha del CCF.
 *
 * Permiso: `dte.ver` (DtePolicy::viewAny / view), el mismo que ver Facturación. La lista
 * existe para facturar lo que falta y enlaza a los CCF, que se abren con ese permiso.
 * `ppq.ver` no alcanzaría para abrir esos enlaces.
 */
class OrdenCompraRecibidaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Dte::class);

        $filtros = $request->validate([
            'ver' => ['nullable', 'in:sin_facturar,todas'],
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);
        $ver = $filtros['ver'] ?? 'sin_facturar';
        $mes = $filtros['mes'] ?? null;

        $ordenes = OrdenCompraRecibida::query()
            ->with('clienteSucursal:id,nombre')
            ->when($ver === 'sin_facturar', fn ($q) => $q->where(fn ($q) => $q->sinFacturar()->orWhere('estado', OrdenCompraRecibida::SIN_LEER)))
            ->when($mes, function ($q, string $mes) {
                $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();

                // Por fecha de creación de la orden; la que no la trae, por cuándo llegó.
                $q->where(fn ($q) => $q->whereBetween('fecha_creacion', [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()])
                    ->orWhere(fn ($q) => $q->whereNull('fecha_creacion')->whereBetween('created_at', [$inicio, $inicio->copy()->endOfMonth()])));
            })
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $ccfs = OrdenCompraRecibida::ccfPorNumero($ordenes->pluck('numero')->all());

        return view('facturacion.ordenes-recibidas', [
            'ordenes' => $ordenes,
            'ccfs' => $ccfs,
            'ver' => $ver,
            'mes' => $mes,
        ]);
    }

    /** El PDF de la orden, tal como llegó. */
    public function pdf(OrdenCompraRecibida $orden): Response
    {
        $this->authorize('viewAny', Dte::class);

        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        abort_unless($disco->exists($orden->pdf_path), 404, 'No se encontró el PDF de la orden de compra.');

        return response((string) $disco->get($orden->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="orden-compra-'.($orden->numero ?? $orden->id).'.pdf"',
            // El archivo viene de un correo externo: que el navegador no adivine otro tipo.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** CCF + orden de compra en un solo PDF, para imprimir de una vez. */
    public function pdfConOrden(Dte $dte, PdfConOrdenCompra $union, DtePdfService $pdfs): Response|RedirectResponse
    {
        $this->authorize('view', $dte);

        $orden = OrdenCompraRecibida::deCcf($dte);
        abort_if($orden === null, 404, 'Este CCF no tiene una orden de compra recibida por correo.');

        try {
            $contenido = $union->unir($dte, $orden);
        } catch (\RuntimeException $e) {
            Log::warning('PDF + orden: no se pudo unir', ['dte_id' => $dte->id, 'orden_id' => $orden->id, 'error' => $e->getMessage()]);

            return redirect()->route('facturacion.show', $dte)->with('error',
                'No se pudo armar «PDF + orden»: el PDF de la orden de compra no se puede unir. Abrí los dos por separado con «Ver PDF» y «Ver orden de compra».');
        }

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.pathinfo($pdfs->nombre($dte), PATHINFO_FILENAME).'-con-orden.pdf"',
        ]);
    }
}
