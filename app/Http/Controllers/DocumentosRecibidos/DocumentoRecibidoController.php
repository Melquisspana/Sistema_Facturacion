<?php

namespace App\Http\Controllers\DocumentosRecibidos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Documentos recibidos (CCF/facturas de proveedores que llegan por correo).
 * Herramienta INTERNA para preparar lo que se le manda a la contadora (ella no
 * entra al sistema). Fase actual: solo lectura/listado/preparación + Excel. NO
 * reenvía, NO envía correos, NO modifica el buzón, NO toca DTE emitidos.
 */
class DocumentoRecibidoController extends Controller
{
    public function index(Request $request, SincronizadorDocumentosRecibidos $sync): View
    {
        // Por defecto: pendientes del mes actual (para que no se llene con el histórico).
        $filtros = DocumentosRecibidosQuery::filtros($request->all());

        $documentos = DocumentosRecibidosQuery::query($filtros)
            ->orderByDesc('fecha_correo')->orderByDesc('id')
            ->paginate($filtros['por_pagina'])->withQueryString();

        return view('documentos-recibidos.index', [
            'documentos' => $documentos,
            'filtros' => $filtros,
            'resumen' => $this->resumen($filtros),
            'fuenteDisponible' => $sync->disponible(),
            'fuente' => $sync->fuente(),
            'conteos' => [
                'pendiente' => DocumentoRecibido::where('estado', 'pendiente')->count(),
                'enviado' => DocumentoRecibido::where('estado', 'enviado')->count(),
                'ignorado' => DocumentoRecibido::where('estado', 'ignorado')->count(),
            ],
        ]);
    }

    /** Descarga el Excel de recibidos respetando los filtros actuales. */
    public function exportar(Request $request, DocumentosRecibidosExcel $excel): BinaryFileResponse
    {
        $filtros = DocumentosRecibidosQuery::filtros($request->all());

        $documentos = DocumentosRecibidosQuery::query($filtros)
            ->orderByDesc('fecha_correo')->orderByDesc('id')->get();

        $ruta = $excel->generar($documentos);

        return response()
            ->download($ruta, $excel->nombreArchivo(DocumentosRecibidosQuery::etiquetaArchivo($filtros)))
            ->deleteFileAfterSend();
    }

    /**
     * Revisa el buzón (Yahoo/IMAP) MANUALMENTE y crea registros nuevos. Solo
     * lectura: no marca leído, no mueve, no borra, no reenvía.
     *
     * Por defecto INCREMENTAL (desde la fecha del último documento guardado); con
     * ?historico=1 revisa todo el buzón (más lento).
     */
    public function sincronizar(Request $request, SincronizadorDocumentosRecibidos $sync): RedirectResponse
    {
        $incremental = ! $request->boolean('historico');
        $r = $sync->sincronizar($incremental);

        if (! $r['disponible'] || $r['error'] !== null) {
            return back()->with('error', $r['error'] ?? 'No se pudo revisar el correo.');
        }

        $desde = $r['incremental'] ? ('desde el '.($r['desde'] ?? '—')) : 'todo el histórico';
        return back()->with('status', "Revisión completada (carpeta {$r['carpeta']}, {$desde}): "
            ."{$r['revisados']} correos revisados, {$r['nuevos']} nuevos, {$r['duplicados']} ya registrados, "
            ."{$r['sin_datos']} sin DTE legible. No se modificó ningún correo.");
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

    /**
     * Marca el documento como enviado a contabilidad MANUALMENTE (estado interno).
     * NO envía ningún correo: solo registra que ya se lo hiciste llegar por fuera.
     * El envío automático a contabilidad llega en una fase posterior.
     */
    public function marcarEnviado(DocumentoRecibido $documento): RedirectResponse
    {
        $documento->update(['estado' => 'enviado']);

        return back()->with('status', 'Documento marcado como enviado a contabilidad (manual, no se envió correo).');
    }

    /**
     * Resumen del rango/filtro actual (sin el filtro de estado de la pestaña): total
     * de documentos, monto total y desglose por estado. Solo lectura.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function resumen(array $filtros): array
    {
        $base = DocumentosRecibidosQuery::query($filtros, aplicarEstado: false);

        return [
            'total_docs' => (clone $base)->count(),
            'total_monto' => (float) (clone $base)->sum('total'),
            'pendiente' => (clone $base)->where('estado', 'pendiente')->count(),
            'enviado' => (clone $base)->where('estado', 'enviado')->count(),
            'ignorado' => (clone $base)->where('estado', 'ignorado')->count(),
        ];
    }
}
