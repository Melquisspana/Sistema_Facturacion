<?php

namespace App\Http\Controllers\Contabilidad;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarPaqueteContabilidad;
use App\Jobs\GenerarPaqueteContabilidad;
use App\Services\Contabilidad\AuditoriaPaquete;
use App\Services\Contabilidad\CoberturaPaquete;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Paquete mensual para contabilidad (herramienta INTERNA; la contadora no entra al
 * sistema). Junta COMPRAS (documentos recibidos) y VENTAS (reporte contadora) por
 * rango, muestra un resumen y genera un ZIP para enviarlo por fuera.
 *
 * El ZIP y el envío se arman EN SEGUNDO PLANO ({@see GenerarPaqueteContabilidad},
 * {@see EnviarPaqueteContabilidad}): un mes completo regenera un PDF por venta y pasaba
 * de los 100 s que Cloudflare espera una respuesta. La pantalla consulta el estado
 * ({@see EstadoPaquete}) hasta que está listo.
 *
 * SOLO LECTURA: no vuelve a descargar correos, no toca DTE emitidos, correlativos,
 * firmador ni transmisión. No cambia estados al generar el ZIP.
 */
class PaqueteContabilidadController extends Controller
{
    /** Frase exacta que el usuario debe escribir para confirmar el envío. */
    public const FRASE_ENVIO = 'ENVIAR A CONTABILIDAD';

    public function __construct(private readonly PeriodoPaquete $periodo) {}

    public function index(Request $request, CoberturaPaquete $cobertura): View
    {
        $rango = $this->periodo->rango($request);
        $compras = $this->periodo->compras($rango);
        $ventas = $this->periodo->ventas($rango);
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);

        // Cobertura del período ANTES de mostrar cualquier total: los números de abajo
        // solo significan algo si se sabe que los correos del período están leídos.
        $cob = $cobertura->para($rango['desde'], $rango['hasta']);

        $resumen = [
            'compras_cantidad' => $compras->count(),
            'compras_total' => round((float) $compras->sum('total'), 2),
            'ventas_cantidad' => $ventas->count(),
            'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            // Faltantes de adjuntos (solo lectura de datos ya guardados): compras sin PDF /
            // sin JSON (tiene_pdf/tiene_json) y ventas sin el JSON oficial (json_generado_path).
            'compras_sin_pdf' => $compras->filter(fn ($d) => ! $d->tiene_pdf)->count(),
            'compras_sin_json' => $compras->filter(fn ($d) => ! $d->tiene_json)->count(),
            'ventas_sin_json' => $ventas->filter(fn ($d) => blank($d->json_generado_path))->count(),
        ];

        $correo = $this->correoContabilidad();
        // El botón "Enviar a contabilidad" solo se habilita si el usuario tiene el
        // permiso contabilidad.enviar (admin/contabilidad; facturación NO), hay correo
        // válido y alguna fuente incluida tiene documentos en el rango. El backend lo
        // refuerza con permission:contabilidad.enviar en la ruta.
        $puedeEnviarPermiso = (bool) $request->user()?->can('contabilidad.enviar');
        $hayCompras = $incluirCompras && $resumen['compras_cantidad'] > 0;
        $hayVentas = $incluirVentas && $resumen['ventas_cantidad'] > 0;

        // Un período sin cubrir bloquea el ENVÍO, no la descarga. Ver el porqué en
        // {@see self::enviar()}. La pantalla lo refleja para que el botón no prometa
        // algo que el servidor va a rechazar.
        $bloqueaCobertura = $incluirCompras && $cob['bloquea_envio'];

        $usuarioId = (int) $request->user()->id;

        return view('contabilidad.paquete', [
            'rango' => $rango,
            'incluirCompras' => $incluirCompras,
            'incluirVentas' => $incluirVentas,
            'resumen' => $resumen,
            'cobertura' => $cob,
            'correoContabilidad' => $correo,
            'puedeEnviar' => $puedeEnviarPermiso && $correo !== null && ($hayCompras || $hayVentas) && ! $bloqueaCobertura,
            'bloqueaCobertura' => $bloqueaCobertura,
            'fraseEnvio' => self::FRASE_ENVIO,
            'ultimoEnvio' => $this->ultimoEnvioExitoso(),
            'estadoZip' => EstadoPaquete::para($usuarioId, 'zip', $rango, $incluirCompras, $incluirVentas)->leer(),
            'estadoEnvio' => EstadoPaquete::para($usuarioId, 'envio', $rango, $incluirCompras, $incluirVentas)->leer(),
            'filtros' => $this->filtros($request, $rango, $incluirCompras, $incluirVentas),
        ]);
    }

    /**
     * Último envío EXITOSO del paquete, leído del activity log ya existente
     * ('paquete_contabilidad', estado 'enviado'). Solo lectura; no persiste nada nuevo.
     * Devuelve null si no hay envíos previos.
     *
     * @return array{fecha: Carbon, etiqueta: ?string, correo: ?string, usuario: ?string, compras: mixed, ventas: mixed}|null
     */
    private function ultimoEnvioExitoso(): ?array
    {
        $act = Activity::query()
            ->where('log_name', 'paquete_contabilidad')
            ->where('properties->estado', 'enviado')
            ->with('causer')
            ->latest('id')
            ->first();

        if ($act === null) {
            return null;
        }

        return [
            'fecha' => $act->created_at,
            'etiqueta' => $act->getExtraProperty('etiqueta'),
            'correo' => $act->getExtraProperty('correo_destino'),
            'usuario' => $act->causer?->name,
            'compras' => $act->getExtraProperty('compras_cantidad'),
            'ventas' => $act->getExtraProperty('ventas_cantidad'),
        ];
    }

    /**
     * Pide el ZIP del período: lo arma un job y la pantalla avisa cuando está listo.
     *
     * La descarga NO se bloquea aunque el período esté incompleto: es la forma de
     * revisar qué hay mientras se recupera lo que falta, y a veces la contadora necesita
     * un avance. Lo que no puede pasar es que un paquete incompleto se confunda con uno
     * cerrado, así que el aviso viaja CON el archivo: el nombre lleva `_INCOMPLETO` y el
     * LEEME.txt abre con los días faltantes. La cobertura la recalcula el job.
     */
    public function generar(Request $request): RedirectResponse
    {
        $incluirCompras = $request->boolean('incluir_compras', true);
        $incluirVentas = $request->boolean('incluir_ventas', true);
        if (! $incluirCompras && ! $incluirVentas) {
            return back()->with('error', 'Elegí al menos una fuente (compras o ventas) para generar el paquete.');
        }

        $rango = $this->periodo->rango($request);
        $usuarioId = (int) $request->user()->id;
        $volver = redirect()->route('contabilidad.paquete', $this->filtros($request, $rango, $incluirCompras, $incluirVentas));

        $estado = EstadoPaquete::para($usuarioId, 'zip', $rango, $incluirCompras, $incluirVentas);
        if ($estado->enCurso()) {
            return $volver->with('error', 'Este paquete ya se está generando. Esperá a que termine.');
        }

        EstadoPaquete::limpiarViejos();
        $estado->iniciar('generando', 'Generando el paquete…');
        GenerarPaqueteContabilidad::dispatch($usuarioId, $rango, $incluirCompras, $incluirVentas);

        // Con la cola sincrónica (desarrollo, pruebas) el job ya terminó.
        return match ($estado->leer()['estado'] ?? null) {
            'listo' => $volver->with('status', 'El paquete está listo: descargalo abajo.'),
            'error' => $volver->with('error', (string) $estado->leer()['mensaje']),
            default => $volver->with('status', 'Generando el paquete. Puede tardar unos minutos: esta pantalla avisa cuando esté listo.'),
        };
    }

    /** Estado del ZIP y del envío del período, para que la pantalla se actualice sola. */
    public function estado(Request $request): JsonResponse
    {
        $rango = $this->periodo->rango($request);
        $compras = $request->boolean('incluir_compras', true);
        $ventas = $request->boolean('incluir_ventas', true);
        $usuarioId = (int) $request->user()->id;

        return response()->json([
            'zip' => EstadoPaquete::para($usuarioId, 'zip', $rango, $compras, $ventas)->leer()['estado'] ?? null,
            'envio' => EstadoPaquete::para($usuarioId, 'envio', $rango, $compras, $ventas)->leer()['estado'] ?? null,
        ]);
    }

    /**
     * Descarga el ZIP ya armado. Cada usuario solo ve los suyos: la ruta sale del usuario
     * autenticado, no de un parámetro.
     */
    public function descargar(Request $request): StreamedResponse
    {
        $rango = $this->periodo->rango($request);
        $estado = EstadoPaquete::para(
            (int) $request->user()->id, 'zip', $rango,
            $request->boolean('incluir_compras', true), $request->boolean('incluir_ventas', true),
        );
        $datos = $estado->leer();

        abort_unless(($datos['estado'] ?? null) === 'listo' && Storage::disk('local')->exists($estado->rutaZip()), 404);

        return Storage::disk('local')->download($estado->rutaZip(), (string) $datos['nombre_descarga']);
    }

    /**
     * Envía el MISMO paquete mensual por correo a `contabilidad.correo`. Solo tras
     * confirmación con la frase exacta. Un único correo, sin BCC ni copias. El armado y
     * el envío los hace {@see EnviarPaqueteContabilidad} en segundo plano; acá quedan
     * todas las guardas, para que un pedido inválido ni siquiera llegue a la cola.
     *
     * COBERTURA: si el período de compras no está completamente revisado, el envío se
     * BLOQUEA. No hay forma de forzarlo desde acá, y es deliberado. Este botón hace dos
     * cosas irreversibles a la vez: manda un correo que sale del sistema y marca las
     * compras como `enviado`. Ese cambio de estado es el que después hace invisible el
     * hueco —las compras que faltaban ya no aparecen como pendientes de nadie— así que
     * un paquete incompleto enviado no se nota hasta que lo nota la contadora. La
     * descarga sí queda disponible, marcada como incompleta: quien de verdad necesite
     * mandar un avance puede bajarlo y enviarlo a mano, con el aviso puesto. Un atajo en
     * el código agregaría riesgo sin agregar ninguna capacidad que no exista ya.
     */
    public function enviar(Request $request, PaqueteContabilidadZip $zip, CoberturaPaquete $cobertura, AuditoriaPaquete $auditoria): RedirectResponse
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
        $rango = $this->periodo->rango($request);
        $compras = $incluirCompras ? $this->periodo->compras($rango) : new Collection;
        $ventas = $incluirVentas ? $this->periodo->ventas($rango) : new Collection;
        if ($compras->isEmpty() && $ventas->isEmpty()) {
            return back()->with('error', 'No hay documentos en el rango seleccionado: no hay nada que enviar.');
        }

        // 4) El período de compras tiene que estar cubierto. Se recalcula acá y no se
        //    hereda de la pantalla: es la última barrera antes de que el correo salga.
        $cob = $incluirCompras ? $cobertura->para($rango['desde'], $rango['hasta']) : null;
        if ($cob !== null && $cob['bloquea_envio']) {
            $auditoria->registrar($request->user(), 'bloqueado', $correo, $rango, [
                'compras_cantidad' => $compras->count(), 'compras_total' => round((float) $compras->sum('total'), 2),
                'ventas_cantidad' => $ventas->count(), 'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            ], $zip->nombreArchivo($rango['etiqueta'], true), $cob['motivo'], null, $cob);

            return back()->with('error', 'No se envió nada: el período de compras no está completo. '
                .$cob['motivo'].' Podés descargar el paquete marcado como incompleto mientras tanto.');
        }

        // 5) Candado: no se lanza un segundo envío igual mientras el primero sigue en curso.
        $usuarioId = (int) $request->user()->id;
        $volver = redirect()->route('contabilidad.paquete', $this->filtros($request, $rango, $incluirCompras, $incluirVentas));
        $estado = EstadoPaquete::para($usuarioId, 'envio', $rango, $incluirCompras, $incluirVentas);
        if ($estado->enCurso()) {
            return $volver->with('error', 'Este paquete ya se está enviando. Esperá a que termine.');
        }

        EstadoPaquete::limpiarViejos();
        $estado->iniciar('enviando', 'Preparando y enviando el paquete a '.$correo.'…');
        EnviarPaqueteContabilidad::dispatch($usuarioId, $correo, $rango, $incluirCompras, $incluirVentas);

        // Con la cola sincrónica (desarrollo, pruebas) el job ya terminó.
        $final = $estado->leer();

        return match ($final['estado'] ?? null) {
            'enviado', 'simulado' => $volver->with('status', (string) $final['mensaje']),
            'envio_fallido' => $volver->with('error', (string) $final['mensaje']),
            default => $volver->with('status', 'Enviando el paquete a '.$correo.'. Puede tardar unos minutos: esta pantalla avisa cuando termine.'),
        };
    }

    /** Correo de contabilidad configurado, o null si no existe o no es válido. */
    private function correoContabilidad(): ?string
    {
        return app(CorreoContabilidad::class)->direccion();
    }

    /**
     * Filtros de la pantalla, para volver a ella y para consultar/descargar el mismo
     * paquete que se pidió.
     *
     * @param  array{mes: int, anio: int}  $rango
     * @return array<string, mixed>
     */
    private function filtros(Request $request, array $rango, bool $incluirCompras, bool $incluirVentas): array
    {
        return array_filter([
            'mes' => $rango['mes'],
            'anio' => $rango['anio'],
            'fecha_desde' => $request->input('fecha_desde'),
            'fecha_hasta' => $request->input('fecha_hasta'),
            'incluir_compras' => $incluirCompras ? 1 : 0,
            'incluir_ventas' => $incluirVentas ? 1 : 0,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
