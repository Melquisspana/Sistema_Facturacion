<?php

namespace App\Http\Controllers\Contabilidad;

use App\Http\Controllers\Controller;
use App\Mail\PaqueteContabilidadCorreo;
use App\Models\Configuracion;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\Reportes\ReporteContadoraQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Paquete mensual para contabilidad (herramienta INTERNA; la contadora no entra al
 * sistema). Junta COMPRAS (documentos recibidos) y VENTAS (reporte contadora) por
 * rango, muestra un resumen y genera un ZIP para enviarlo por fuera.
 *
 * SOLO LECTURA: no vuelve a descargar correos, no envía nada, no toca DTE emitidos,
 * correlativos, firmador ni transmisión. No cambia estados al generar el ZIP.
 */
class PaqueteContabilidadController extends Controller
{
    /** Frase exacta que el usuario debe escribir para confirmar el envío. */
    public const FRASE_ENVIO = 'ENVIAR A CONTABILIDAD';

    public function index(Request $request): View
    {
        $rango = $this->rango($request);
        $compras = $this->compras($rango);
        $ventas = $this->ventas($rango);
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);

        $resumen = [
            'compras_cantidad' => $compras->count(),
            'compras_total' => round((float) $compras->sum('total'), 2),
            'ventas_cantidad' => $ventas->count(),
            'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
        ];

        $correo = $this->correoContabilidad();
        // El botón "Enviar a contabilidad" solo se habilita si hay correo válido y
        // alguna fuente incluida tiene documentos en el rango.
        $hayCompras = $incluirCompras && $resumen['compras_cantidad'] > 0;
        $hayVentas = $incluirVentas && $resumen['ventas_cantidad'] > 0;

        return view('contabilidad.paquete', [
            'rango' => $rango,
            'incluirCompras' => $incluirCompras,
            'incluirVentas' => $incluirVentas,
            'resumen' => $resumen,
            'correoContabilidad' => $correo,
            'puedeEnviar' => $correo !== null && ($hayCompras || $hayVentas),
            'fraseEnvio' => self::FRASE_ENVIO,
        ]);
    }

    public function generar(Request $request, PaqueteContabilidadZip $zip): BinaryFileResponse|RedirectResponse
    {
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);
        if (! $incluirCompras && ! $incluirVentas) {
            return back()->with('error', 'Elegí al menos una fuente (compras o ventas) para generar el paquete.');
        }

        $rango = $this->rango($request);
        $compras = $incluirCompras ? $this->compras($rango) : new Collection();
        $ventas = $incluirVentas ? $this->ventas($rango) : new Collection();

        $r = $zip->generar($rango['etiqueta'], $compras, $ventas, $incluirCompras, $incluirVentas);

        return response()
            ->download($r['ruta'], $zip->nombreArchivo($rango['etiqueta']))
            ->deleteFileAfterSend();
    }

    /**
     * Envía el MISMO paquete mensual por correo a `contabilidad.correo`. Solo tras
     * confirmación con la frase exacta. Un único correo, sin BCC ni copias.
     *
     * NO toca DTE emitidos, correlativos, firmador, transmisión a Hacienda ni el
     * buzón Yahoo. NO marca documentos como enviados: solo manda el correo y registra
     * auditoría. Si el envío falla: no cambia estados, no borra el ZIP y avisa claro.
     */
    public function enviar(Request $request, PaqueteContabilidadZip $zip): RedirectResponse
    {
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);
        if (! $incluirCompras && ! $incluirVentas) {
            return back()->with('error', 'Elegí al menos una fuente (compras o ventas) para enviar el paquete.');
        }

        // 1) Frase exacta obligatoria (guardia de servidor; también hay guardia en el navegador).
        if (trim((string) $request->input('frase')) !== self::FRASE_ENVIO) {
            return back()->with('error', 'Para enviar debés escribir la frase exacta: '.self::FRASE_ENVIO);
        }

        // 2) Correo de contabilidad configurado y válido.
        $correo = $this->correoContabilidad();
        if ($correo === null) {
            return back()->with('error', 'No hay un correo de contabilidad válido. Configuralo en Configuración > Contabilidad.');
        }

        // 3) Debe haber documentos en el rango para las fuentes incluidas.
        $rango = $this->rango($request);
        $compras = $incluirCompras ? $this->compras($rango) : new Collection();
        $ventas = $incluirVentas ? $this->ventas($rango) : new Collection();
        if ($compras->isEmpty() && $ventas->isEmpty()) {
            return back()->with('error', 'No hay documentos en el rango seleccionado: no hay nada que enviar.');
        }

        $resumen = [
            'compras_cantidad' => $compras->count(),
            'compras_total' => round((float) $compras->sum('total'), 2),
            'ventas_cantidad' => $ventas->count(),
            'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            'desde' => $rango['desde'],
            'hasta' => $rango['hasta'],
            'incluir_compras' => $incluirCompras,
            'incluir_ventas' => $incluirVentas,
        ];

        // 4) Mismo ZIP que el paquete mensual.
        $r = $zip->generar($rango['etiqueta'], $compras, $ventas, $incluirCompras, $incluirVentas);
        $nombreZip = $zip->nombreArchivo($rango['etiqueta']);

        try {
            $bytes = (string) file_get_contents($r['ruta']);
            // 5) Un solo correo a contabilidad, con el ZIP adjunto.
            Mail::to($correo)->send(new PaqueteContabilidadCorreo($rango['etiqueta'], $bytes, $nombreZip, $resumen));
        } catch (Throwable $e) {
            // Falla: no cambia estados, no borra el ZIP; registra auditoría "fallido".
            $this->auditar('fallido', $correo, $rango, $resumen, $nombreZip, $e->getMessage());

            return back()->with('error', 'No se pudo enviar el paquete a contabilidad: '.$e->getMessage().' (no se cambió ningún estado).');
        }

        // Éxito: registra auditoría y limpia el temporal. NO marca documentos como enviados.
        $this->auditar('enviado', $correo, $rango, $resumen, $nombreZip, null);
        @unlink($r['ruta']);

        return back()->with('status', "Paquete {$rango['etiqueta']} enviado a {$correo} ({$resumen['compras_cantidad']} compras, {$resumen['ventas_cantidad']} ventas). No se cambió ningún estado ni documento.");
    }

    /** Correo de contabilidad configurado, o null si no existe o no es válido. */
    private function correoContabilidad(): ?string
    {
        $correo = trim((string) Configuracion::get('contabilidad.correo'));

        return $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
    }

    /** Registra la auditoría del intento de envío (usuario, destino, rango, conteos, ZIP, estado). */
    private function auditar(string $estado, string $correo, array $rango, array $resumen, string $nombreZip, ?string $error): void
    {
        activity('paquete_contabilidad')
            ->causedBy(auth()->user())
            ->withProperties(array_filter([
                'correo_destino' => $correo,
                'rango' => $rango['desde'].' a '.$rango['hasta'],
                'etiqueta' => $rango['etiqueta'],
                'compras_cantidad' => $resumen['compras_cantidad'],
                'compras_total' => $resumen['compras_total'],
                'ventas_cantidad' => $resumen['ventas_cantidad'],
                'ventas_total' => $resumen['ventas_total'],
                'zip' => $nombreZip,
                'estado' => $estado,
                'error' => $error,
            ], fn ($v) => $v !== null))
            ->log("Envío de paquete de contabilidad {$rango['etiqueta']}: {$estado}");
    }

    /** Compras del rango (documentos recibidos). Reutiliza el query del módulo. */
    private function compras(array $rango): Collection
    {
        $f = DocumentosRecibidosQuery::filtros([
            'vista' => 'bandeja', 'rango' => 'personalizado',
            'fecha_desde' => $rango['desde'], 'fecha_hasta' => $rango['hasta'],
        ]);

        return DocumentosRecibidosQuery::query($f)->orderBy('fecha_correo')->get();
    }

    /** Ventas del rango (documentos emitidos). Reutiliza el query del Reporte contadora. */
    private function ventas(array $rango): Collection
    {
        $f = ReporteContadoraQuery::filtros([
            'fecha_desde' => $rango['desde'], 'fecha_hasta' => $rango['hasta'],
        ]);

        return ReporteContadoraQuery::query($f)->get();
    }

    /**
     * Resuelve el rango: fecha_desde/hasta explícitas, o mes+año (default mes actual).
     *
     * @return array{desde: string, hasta: string, etiqueta: string, mes: int, anio: int}
     */
    private function rango(Request $request): array
    {
        $desde = $this->fecha($request->input('fecha_desde'));
        $hasta = $this->fecha($request->input('fecha_hasta'));

        if ($desde && $hasta) {
            $d = Carbon::parse($desde);
            $h = Carbon::parse($hasta);
            $etiqueta = $d->isSameMonth($h) ? $d->format('Y-m') : $d->format('Y-m-d').'_a_'.$h->format('Y-m-d');

            return ['desde' => $desde, 'hasta' => $hasta, 'etiqueta' => $etiqueta, 'mes' => (int) $d->month, 'anio' => (int) $d->year];
        }

        $mes = max(1, min(12, (int) $request->input('mes', now()->month)));
        $anio = (int) $request->input('anio', now()->year);
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();

        return [
            'desde' => $inicio->toDateString(),
            'hasta' => $inicio->copy()->endOfMonth()->toDateString(),
            'etiqueta' => $inicio->format('Y-m'),
            'mes' => $mes,
            'anio' => $anio,
        ];
    }

    private function fecha(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
