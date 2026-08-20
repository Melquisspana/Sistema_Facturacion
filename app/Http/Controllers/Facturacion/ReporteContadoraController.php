<?php

namespace App\Http\Controllers\Facturacion;

use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Services\Dte\EnvioDteCorreoService;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte para la contadora ("Ventas"): listado + Excel de los DTE de ESTE sistema, y
 * el envío INDIVIDUAL de un documento a contabilidad por correo. Por defecto excluye
 * pruebas/mock (ambiente 01 + aceptados reales por Hacienda).
 *
 * El envío reutiliza el pipeline encolado existente (EnvioDteCorreoService + job
 * EnviarDteCorreo) con canal `contabilidad`. NO emite, NO transmite, no firma, no toca
 * correlativos ni el estado fiscal del documento: solo manda lo ya aceptado.
 */
class ReporteContadoraController extends Controller
{
    /** Pantalla con filtros + vista previa de resultados. */
    public function index(Request $request): View
    {
        $filtros = ReporteContadoraQuery::filtros($request->all());

        $dtes = ReporteContadoraQuery::query($filtros)
            ->limit(500) // vista previa acotada; el Excel exporta todo el rango
            ->get();

        return view('facturacion.reporte-contadora', [
            'filtros' => $filtros,
            'tipos' => ReporteContadoraQuery::TIPOS,
            'dtes' => $dtes,
            'correoContabilidad' => $this->correoContabilidad(),
            // El botón de envío es solo para administrador/contabilidad; la ruta lo
            // refuerza con permission:contabilidad.enviar (defensa en profundidad).
            'puedeEnviar' => (bool) $request->user()?->can('contabilidad.enviar'),
        ]);
    }

    /** Descarga el Excel con TODOS los documentos del rango filtrado. */
    public function exportar(Request $request, ReporteContadoraExcel $excel): BinaryFileResponse
    {
        $filtros = ReporteContadoraQuery::filtros($request->all());

        $dtes = ReporteContadoraQuery::query($filtros)->get();

        $ruta = $excel->generar($dtes);

        return response()
            ->download($ruta, $excel->nombreArchivo($filtros['fecha_desde'], $filtros['fecha_hasta']))
            ->deleteFileAfterSend();
    }

    /**
     * Descarga el JSON oficial ya generado de un documento, para el registro contable.
     * Acceso con `reportes.ver` (la ruta lo exige): contabilidad no tiene `dte.emitir`,
     * por eso no usa la ruta de JSON de Facturación. SOLO lectura del archivo local:
     * nunca genera el JSON (eso movería la numeración oficial). Sin JSON → 404.
     */
    public function descargarJson(Dte $dte): StreamedResponse
    {
        abort_unless($dte->aceptadoRealmentePorMh(), 403);

        $disco = (string) config('dte.storage.disk', 'local');
        abort_unless(filled($dte->json_generado_path) && Storage::disk($disco)->exists($dte->json_generado_path), 404);

        $nombre = ($dte->numero_control ?: 'dte-'.$dte->id).'.json';

        return response()->streamDownload(
            fn () => print (string) Storage::disk($disco)->get($dte->json_generado_path),
            preg_replace('/[^A-Za-z0-9_.-]+/', '_', $nombre),
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Envía UN documento a contabilidad por correo (PDF + JSON oficial), encolado. Solo
     * documentos ACEPTADOS REALMENTE por Hacienda y solo al correo configurado en
     * Configuración > Contabilidad. Reutiliza el servicio compartido con canal
     * `contabilidad`: el anti-duplicado por canal evita encolar dos veces lo mismo.
     *
     * No cambia el estado fiscal, ni sello, ni correlativos: el resultado del envío vive
     * en el historial `dte_envios` (en cola → enviado | simulado | error).
     */
    public function enviarContabilidad(Request $request, Dte $dte, EnvioDteCorreoService $envios): RedirectResponse
    {
        abort_unless($dte->aceptadoRealmentePorMh(), 403);

        $correo = $this->correoContabilidad();
        if ($correo === null) {
            return back()->with('error', 'No hay un correo de contabilidad válido. Configuralo en Configuración > Contabilidad.');
        }

        $envio = $envios->encolar($dte, [$correo], $request->user()?->id, DteEnvio::CANAL_CONTABILIDAD);

        if ($envio === null) {
            return back()->with('status', 'Ese documento ya está en cola para contabilidad; no se duplicó.');
        }

        return back()->with('status', 'Documento en cola para envío a contabilidad ('.$correo.').');
    }

    /** Correo de contabilidad configurado, o null si no existe o no es válido. */
    private function correoContabilidad(): ?string
    {
        return app(CorreoContabilidad::class)->direccion();
    }
}
