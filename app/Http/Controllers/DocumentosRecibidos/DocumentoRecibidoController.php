<?php

namespace App\Http\Controllers\DocumentosRecibidos;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\DocumentosRecibidos\EnvioDocumentoRecibidoService;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos recibidos (CCF/facturas de proveedores que llegan por correo).
 * Herramienta INTERNA para preparar lo que se le manda a la contadora (ella no
 * entra al sistema): listado, Excel, apertura de los adjuntos guardados y envío
 * INDIVIDUAL a contabilidad (encolado).
 *
 * El buzón Yahoo/IMAP sigue siendo estrictamente de SOLO LECTURA: el envío usa los
 * archivos ya guardados en disco, nunca vuelve al buzón. NO toca DTE emitidos,
 * correlativos, firma ni transmisión.
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
            ."{$r['descartados']} descartados (no-DTE), {$r['sin_datos']} sin DTE legible. No se modificó ningún correo.");
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
     * Envía ESTE documento recibido a contabilidad por correo (encolado), con los
     * adjuntos originales ya guardados. No hay marcado manual: la compra solo pasa a
     * "enviado" cuando el envío termina realmente bien (lo hace el job).
     *
     * Nunca toca el buzón Yahoo: los archivos se leen del disco local.
     */
    public function enviarContabilidad(
        Request $request,
        DocumentoRecibido $documento,
        EnvioDocumentoRecibidoService $envios,
        AdjuntosDocumentoRecibido $adjuntos,
    ): RedirectResponse {
        $correo = $this->correoContabilidad();
        if ($correo === null) {
            return back()->with('error', 'No hay un correo de contabilidad válido. Configuralo en Configuración > Contabilidad.');
        }

        // Sin ningún archivo que quepa no se manda un correo vacío: se avisa claro.
        $seleccion = $adjuntos->seleccionar($documento);
        if ($seleccion['enviados'] === []) {
            $mensaje = $seleccion['omitidos'] === []
                ? 'Este documento no tiene adjuntos guardados: no hay nada que enviar a contabilidad.'
                : 'Ningún adjunto de este documento cabe en el límite de '.$this->mb($adjuntos->maxBytes()).' MB por correo. No se envió nada.';

            return back()->with('error', $mensaje);
        }

        $envio = $envios->encolar($documento, [$correo], $request->user()?->id);
        if ($envio === null) {
            return back()->with('status', 'Ese documento ya está en cola para contabilidad; no se duplicó.');
        }

        $aviso = $seleccion['omitidos'] === [] ? '' : ' Se omitieron por tamaño: '.$adjuntos->nombres($seleccion['omitidos']).'.';

        return back()->with('status', 'Documento en cola para envío a contabilidad ('.$correo.') con: '
            .$adjuntos->nombres($seleccion['enviados']).'.'.$aviso);
    }

    /**
     * Abre/descarga un adjunto YA GUARDADO del documento (pdf | json). Solo lectura de
     * disco: no vuelve al buzón y no genera nada. Sin archivo guardado → 404.
     */
    public function descargarArchivo(DocumentoRecibido $documento, string $tipo): StreamedResponse
    {
        abort_unless(in_array($tipo, ['pdf', 'json'], true), 404);

        $ruta = collect((array) data_get($documento->metadata_json, 'archivos', []))
            ->first(fn ($r) => is_string($r)
                && str_ends_with(strtolower($r), '.'.$tipo)
                && Storage::disk('local')->exists($r));

        abort_if($ruta === null, 404);

        return response()->streamDownload(
            fn () => print (string) Storage::disk('local')->get($ruta),
            basename($ruta),
            [
                'Content-Type' => $tipo === 'pdf' ? 'application/pdf' : 'application/json',
                // El PDF se abre en el navegador; el JSON se descarga.
                'Content-Disposition' => ($tipo === 'pdf' ? 'inline' : 'attachment').'; filename="'.basename($ruta).'"',
            ],
        );
    }

    /** Correo de contabilidad configurado, o null si no existe o no es válido. */
    private function correoContabilidad(): ?string
    {
        $correo = strtolower(trim((string) Configuracion::get('contabilidad.correo')));

        return ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) ? $correo : null;
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
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
