<?php

namespace App\Http\Controllers\DocumentosRecibidos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documentos recibidos (CCF/facturas que nos llegan por correo). Fase 1: solo
 * lectura/listado + preparación. NO reenvía, NO envía correos, NO modifica correos
 * en Gmail, NO borra nada, NO toca DTE emitidos ni correlativos.
 */
class DocumentoRecibidoController extends Controller
{
    /** Pestañas del módulo (bandeja / pendientes / enviados). */
    private const VISTAS = ['bandeja', 'pendientes', 'enviados', 'ignorados'];

    public function index(Request $request, SincronizadorDocumentosRecibidos $sync): View
    {
        $vista = (string) $request->query('vista', 'bandeja');
        if (! in_array($vista, self::VISTAS, true)) {
            $vista = 'bandeja';
        }

        $q = DocumentoRecibido::query();

        // La pestaña define el estado base.
        match ($vista) {
            'pendientes' => $q->where('estado', 'pendiente'),
            'enviados' => $q->where('estado', 'enviado'),
            'ignorados' => $q->where('estado', 'ignorado'),
            default => null, // bandeja = todos
        };

        // Filtros.
        if ($request->filled('emisor')) {
            $q->where('emisor_nombre', 'like', '%'.$request->string('emisor').'%');
        }
        if ($request->filled('tipo_documento')) {
            $q->where('tipo_documento', $request->string('tipo_documento'));
        }
        if ($request->filled('estado') && in_array($request->string('estado')->value(), DocumentoRecibido::ESTADOS, true)) {
            $q->where('estado', $request->string('estado'));
        }
        if ($request->filled('fecha_desde')) {
            $q->whereDate('fecha_correo', '>=', $request->date('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $q->whereDate('fecha_correo', '<=', $request->date('fecha_hasta'));
        }

        $documentos = $q->orderByDesc('fecha_correo')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('documentos-recibidos.index', [
            'documentos' => $documentos,
            'vista' => $vista,
            'fuenteDisponible' => $sync->disponible(),
            'fuente' => $sync->fuente(),
            'conteos' => [
                'pendiente' => DocumentoRecibido::where('estado', 'pendiente')->count(),
                'enviado' => DocumentoRecibido::where('estado', 'enviado')->count(),
                'ignorado' => DocumentoRecibido::where('estado', 'ignorado')->count(),
            ],
        ]);
    }

    /**
     * Revisa el buzón (Yahoo/IMAP) MANUALMENTE y crea registros nuevos. Solo
     * lectura: no marca leído, no mueve, no borra, no reenvía.
     */
    public function sincronizar(SincronizadorDocumentosRecibidos $sync): RedirectResponse
    {
        $r = $sync->sincronizar();

        if (! $r['disponible'] || $r['error'] !== null) {
            return back()->with('error', $r['error'] ?? 'No se pudo revisar el correo.');
        }

        return back()->with('status', "Revisión completada: {$r['revisados']} correos, {$r['nuevos']} nuevos, "
            ."{$r['duplicados']} ya registrados, {$r['sin_datos']} sin DTE legible. No se modificó ningún correo.");
    }

    /** Marca el documento como pendiente para contabilidad. */
    public function marcarPendiente(DocumentoRecibido $documento): RedirectResponse
    {
        $documento->update(['estado' => 'pendiente']);

        return back()->with('status', 'Documento marcado como pendiente para contabilidad.');
    }

    /** Marca el documento como ignorado (no se procesará). */
    public function marcarIgnorado(DocumentoRecibido $documento): RedirectResponse
    {
        $documento->update(['estado' => 'ignorado']);

        return back()->with('status', 'Documento marcado como ignorado.');
    }
}
