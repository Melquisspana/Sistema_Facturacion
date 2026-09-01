<?php

namespace App\Http\Controllers\DocumentosRecibidos;

use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Http\Controllers\Controller;
use App\Jobs\RecuperarPeriodoCompras;
use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\DocumentosRecibidos\EnvioDocumentoRecibidoService;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    public function index(
        Request $request,
        SincronizadorDocumentosRecibidos $sync,
        ProgresoSincronizacionCompras $progreso,
        BitacoraSincronizacionCompras $bitacora,
        ConfiguracionDocumentosRecibidos $configuracion,
    ): View {
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
            // Estado PERMANENTE de la sincronización. Antes el único rastro de una
            // revisión era el mensaje que desaparecía al recargar; con la sincronización
            // automática nadie está mirando cuando corre, así que el estado tiene que
            // poder consultarse en cualquier momento.
            'estadoSync' => $this->estadoSincronizacion($progreso, $bitacora, $configuracion),
            'sinFechaFiscal' => DocumentoRecibido::query()->sinFechaFiscal()->paraContabilidad()->count(),
            'conteos' => [
                'pendiente' => DocumentoRecibido::where('estado', 'pendiente')->count(),
                'enviado' => DocumentoRecibido::where('estado', 'enviado')->count(),
                'ignorado' => DocumentoRecibido::where('estado', 'ignorado')->count(),
            ],
        ]);
    }

    /**
     * Semáforo de la sincronización: al día / con pendientes / con error / sin correr.
     *
     * El umbral de "sin correr" es tres veces el intervalo programado (15 min). Si el
     * scheduler del servidor no está registrado —que es exactamente lo que pasa hoy—, la
     * franja se pone en ámbar sola en menos de una hora en vez de mostrar un verde que
     * no significa nada.
     *
     * @return array{color: string, titulo: string, detalle: string, ultimo_exito: ?string, ultimo_error: ?string, dias_pendientes: int, primer_dia_pendiente: ?string}
     */
    private function estadoSincronizacion(
        ProgresoSincronizacionCompras $progreso,
        BitacoraSincronizacionCompras $bitacora,
        ConfiguracionDocumentosRecibidos $configuracion,
    ): array {
        $carpeta = $configuracion->carpeta();
        $ultimoExito = $bitacora->ultimoExito();
        $ultimoError = $bitacora->ultimoError();

        // Ventana mirada: el mes en curso y el anterior. Es el horizonte en el que
        // todavía se puede armar o rehacer un paquete mensual.
        $desde = now()->subMonthNoOverflow()->startOfMonth();
        $pendientes = $progreso->diasSinCubrir($desde, now()->startOfDay(), $carpeta);

        $minutos = $bitacora->minutosDesdeElUltimoExito();
        [$color, $titulo, $detalle] = match (true) {
            $ultimoError !== null => ['rojo', 'La última sincronización falló', $ultimoError],
            $ultimoExito === null => ['ambar', 'Todavía no hay ninguna sincronización registrada',
                'Corré «Revisar ahora» o recuperá el período que necesites.'],
            $minutos !== null && $minutos > 45 => ['ambar', 'Hace '.$this->tiempo($minutos).' que no se sincroniza',
                'Debería correr cada 15 minutos. Revisá que la tarea programada del servidor esté activa.'],
            $pendientes->isNotEmpty() => ['ambar', $pendientes->count().' día(s) sin revisar',
                'Desde '.$pendientes->first()['dia'].'. Usá «Recuperar período» para cerrarlos.'],
            default => ['verde', 'Sincronización automática al día', 'Todos los días del período están revisados.'],
        };

        return [
            'color' => $color,
            'titulo' => $titulo,
            'detalle' => $detalle,
            'ultimo_exito' => $ultimoExito?->format('d/m/Y H:i'),
            'ultimo_error' => $ultimoError,
            'dias_pendientes' => $pendientes->count(),
            'primer_dia_pendiente' => $pendientes->first()['dia'] ?? null,
        ];
    }

    private function tiempo(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos.' min';
        }

        return $minutos < 1440 ? intdiv($minutos, 60).' h' : intdiv($minutos, 1440).' día(s)';
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
     * "Revisar ahora": adelanta la corrida INCREMENTAL que de todos modos hace el
     * scheduler. Solo lectura del buzón: no marca leído, no mueve, no borra, no reenvía.
     *
     * Ya no existe "Revisar histórico". Prometía recorrer todo el buzón y en realidad
     * leía siempre los mismos correos más recientes, porque el lector ordenaba por UID
     * descendente y recortaba al límite: repetirlo no retrocedía nunca. Su lugar lo ocupa
     * {@see self::recuperar()}, que sí retrocede, por rango de fechas y con progreso.
     */
    public function sincronizar(Request $request, SincronizadorDocumentosRecibidos $sync, ProgresoSincronizacionCompras $progreso, BitacoraSincronizacionCompras $bitacora): RedirectResponse
    {
        if (! $sync->disponible()) {
            return back()->with('error', 'El correo de compras (Yahoo/IMAP) no está configurado. Configuralo en Configuración > Integraciones > Documentos recibidos.');
        }

        $configuracion = app(ConfiguracionDocumentosRecibidos::class);
        $carpeta = $configuracion->carpeta();
        // Mismo solape que la corrida programada, para que apretar el botón y esperar a
        // que corra sola den exactamente el mismo resultado.
        $desde = $progreso->inicioIncremental($carpeta, solapeDias: 2);
        $hasta = now()->startOfDay();

        $bitacora->iniciar();
        $r = $sync->sincronizarRango($desde->min($hasta), $hasta, $configuracion->limite(), aplicar: true);

        // Un buzón caído o unas credenciales rechazadas YA NO se ven como una revisión
        // exitosa sin novedades: salen en rojo, con el motivo y qué hacer.
        if ($r->fallo()) {
            $bitacora->fallo($r->mensaje(), $r->aArreglo());

            return back()->with('error', $r->mensaje());
        }

        $bitacora->exito($r->aArreglo());

        return back()->with('status', $r->mensaje());
    }

    /**
     * "Recuperar período": trae un rango histórico completo, día por día.
     *
     * Es la herramienta EXCEPCIONAL. La sincronización normal la hace el scheduler; esto
     * se usa cuando hay un hueco que cerrar (el sistema estuvo apagado, el buzón rechazó
     * credenciales una semana, o hay backlog anterior al despliegue).
     *
     * Se encola: un mes son decenas de días con sus adjuntos, y hacerlo dentro de la
     * petición web daría un timeout del navegador con la recuperación a medias. El
     * progreso queda en `documentos_recibidos_progreso` y la pantalla lo muestra.
     */
    public function recuperar(Request $request, SincronizadorDocumentosRecibidos $sync): RedirectResponse
    {
        if (! $sync->disponible()) {
            return back()->with('error', 'El correo de compras (Yahoo/IMAP) no está configurado: no hay de dónde recuperar.');
        }

        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [], ['desde' => 'fecha desde', 'hasta' => 'fecha hasta']);

        $desde = Carbon::parse($datos['desde'])->startOfDay();
        $hasta = Carbon::parse($datos['hasta'])->startOfDay();

        // Tope de un año: más que eso casi siempre es una fecha mal tipeada, y una
        // recuperación de cinco años contra Yahoo es una forma segura de que el servidor
        // corte la conexión. Para un rango mayor está el comando, que no tiene tope.
        if ($desde->diffInDays($hasta) > 366) {
            return back()->with('error', 'El período a recuperar no puede pasar de un año. Usá varios tramos, o el comando compras:sincronizar --desde --hasta.');
        }

        // Una sola recuperación a la vez. El candado se toma ACÁ, al encolar, y lo suelta
        // el trabajo al terminar: si solo se tomara al ejecutar, apretar «Recuperar» tres
        // veces dejaría tres trabajos en cola que después se bloquean entre sí de a uno.
        $candado = Cache::lock(RecuperarPeriodoCompras::LOCK, RecuperarPeriodoCompras::LOCK_SEGUNDOS);
        if (! $candado->get()) {
            return back()->with('error', 'Ya hay una recuperación de compras en curso. '
                .'Esperá a que termine —el avance se ve en esta misma pantalla— y volvé a intentarlo.');
        }

        RecuperarPeriodoCompras::dispatch($desde->toDateString(), $hasta->toDateString(), lockOwner: $candado->owner());

        $dias = $desde->diffInDays($hasta) + 1;

        return back()->with('status', "Recuperación encolada: {$dias} día(s), del {$desde->toDateString()} al {$hasta->toDateString()}. "
            .$this->avisoDeCola()
            .' Se lee el buzón día por día y el avance aparece acá mismo. No se modifica ningún correo.');
    }

    /**
     * Aviso sobre quién va a ejecutar la recuperación.
     *
     * Con una cola real, «encolada» significa que NO pasa nada hasta que un worker la
     * tome — y si el worker está caído, el usuario vería un mensaje verde y ningún
     * documento nuevo, que es justo la clase de silencio que este módulo vino a eliminar.
     */
    private function avisoDeCola(): string
    {
        return config('queue.default') === 'sync'
            ? 'Se ejecutó en el momento.'
            : 'La ejecuta el worker de colas: si no está corriendo en el servidor, la recuperación queda esperando.';
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
        return app(CorreoContabilidad::class)->direccion();
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
