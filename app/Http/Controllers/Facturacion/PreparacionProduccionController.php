<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\RespaldoEjecucion;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CorrelativoSistemaNuevo;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Pantalla "Preparar emisión real" (checklist de producción). SOLO LECTURA y
 * ayudas de preparación: NO emite, NO firma, NO transmite, NO mueve correlativos
 * ni envía correos. Reúne el estado que ya calculan servicios existentes
 * (DteTransmisionService, DteFirmaService, WorkerHeartbeat) para que, el día que
 * se decida emitir un CCF real, el operador vea de un vistazo si todo está listo.
 *
 * La frase exacta "EMITIR PRODUCCION" solo se MUESTRA como recordatorio; la
 * guardia real sigue viviendo en DteController@firmarTransmitir (no se toca).
 */
class PreparacionProduccionController extends Controller
{
    /** La frase de seguridad se muestra, NUNCA se ejecuta desde aquí. */
    private const FRASE_EMISION = 'EMITIR PRODUCCION';

    public function index(Request $request, DteTransmisionService $transmision): View
    {
        abort_unless($request->user()?->hasAnyRole(['administrador', 'facturacion']), 403);

        // Estado operativo DTE (modo/candados/mocks). SOLO LECTURA: reutiliza
        // evaluarCandados(); no transmite ni muestra secretos.
        $estado = $transmision->estadoOperativo();

        return view('facturacion.preparar-produccion', [
            'estado' => $estado,
            'frase' => self::FRASE_EMISION,
            'ambienteTransmision' => (string) config('dte.transmision.ambiente', 'testing'),
            'servicios' => $this->servicios(),
            'correlativo' => $this->correlativo(),
            'backup' => $this->ultimoBackup(),
            'puedeBackup' => (bool) $request->user()?->hasRole('administrador'),
            'worker' => WorkerHeartbeat::estado(),
            'higiene' => $this->higiene(),
        ]);
    }

    /**
     * Prueba EN VIVO del firmador local (GET, bajo demanda para no bloquear el
     * render). Reutiliza el health check seguro del servicio de firma: solo hace
     * un GET al /status con timeout y try/catch; no firma nada.
     */
    public function firmador(Request $request, DteFirmaService $firma): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['administrador', 'facturacion']), 403);

        return response()->json($firma->healthCheck());
    }

    /**
     * Genera un backup de base de datos VERIFICADO (mysqldump + SHA-256 + registro en
     * respaldo_ejecuciones) antes de emitir. Es admin-only y deliberadamente inofensivo:
     * no emite ni transmite nada ni toca correlativos. Sincrónico para no depender del
     * worker. Es el MISMO mecanismo que alimenta el check "Backup del día listo" de
     * esta pantalla (coherente con lo que el checklist realmente mide); el zip completo
     * de spatie/laravel-backup sigue corriendo aparte, en su propio horario.
     */
    public function backup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('administrador'), 403);

        try {
            $codigo = Artisan::call('backup:mysql-diario', ['--origen' => 'manual']);
            $salida = trim((string) Artisan::output());

            if ($codigo === 0) {
                return back()->with('status', 'Backup de base de datos generado y verificado. Revisá el "último backup" abajo.');
            }

            return back()->with('error', 'El backup terminó con código '.$codigo.'. Detalle: '.$this->cola($salida));
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo generar el backup de BD: '.$e->getMessage()
                .' No se emitió ni transmitió nada.');
        }
    }

    /**
     * Servicios necesarios para emitir. SOLO LECTURA y sin llamadas externas en el
     * render (el firmador se prueba aparte, bajo demanda): app, base de datos, worker
     * y la CONFIGURACIÓN de firmador/SMTP (sin secretos).
     *
     * @return array<int, array{clave: string, label: string, estado: string, valor: string, detalle: string}>
     */
    private function servicios(): array
    {
        // Base de datos: intento de conexión (getPdo), sin consultar nada sensible.
        $dbOk = true;
        $dbDetalle = 'Conexión establecida ('.config('database.default').').';
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $dbOk = false;
            $dbDetalle = 'No responde la base de datos.';
        }

        $hb = WorkerHeartbeat::estado();
        $workerEstado = ['activo' => 'ok', 'inactivo' => 'advertencia', 'sin_datos' => 'info'][$hb['estado']] ?? 'info';
        $workerDetalle = match ($hb['estado']) {
            'activo' => 'Worker activo — último pulso '.$hb['hace'].'.',
            'inactivo' => 'Worker posiblemente detenido — último pulso '.$hb['hace'].'. Abrí start-queue.bat.',
            default => 'Sin datos: el worker aún no reportó actividad (corré start-queue.bat).',
        };

        // Firmador: solo CONFIGURACIÓN en el render (la prueba en vivo es aparte).
        $firmadorUrl = (string) config('dte.firmador.url');
        $firmaMock = (bool) config('dte.firma.mock', false);
        $firmaEnabled = (bool) config('dte.firma.enabled', false);

        // SMTP: solo config visible, SIN password ni secretos.
        $mailer = (string) config('mail.default');
        $mailHost = (string) config('mail.mailers.smtp.host', '');
        $mailFrom = (string) config('mail.from.address', '');
        $smtpConfigurado = $mailFrom !== '' && ($mailer !== 'smtp' || $mailHost !== '');

        return [
            [
                'clave' => 'app', 'label' => 'Aplicación (Laravel)', 'estado' => 'ok',
                'valor' => 'responde', 'detalle' => 'Laravel '.app()->version().' · PHP '.PHP_VERSION.'.',
            ],
            [
                'clave' => 'db', 'label' => 'Base de datos', 'estado' => $dbOk ? 'ok' : 'critico',
                'valor' => $dbOk ? 'responde' : 'sin respuesta', 'detalle' => $dbDetalle,
            ],
            [
                'clave' => 'worker', 'label' => 'Worker / cola', 'estado' => $workerEstado,
                'valor' => $hb['estado'], 'detalle' => $workerDetalle,
            ],
            [
                'clave' => 'firmador', 'label' => 'Firmador Java local', 'estado' => 'info',
                'valor' => $firmaMock ? 'MOCK' : ($firmaEnabled ? 'habilitado' : 'preparación'),
                'detalle' => 'URL '.$firmadorUrl.' · firma '.($firmaEnabled ? 'HABILITADA' : 'deshabilitada')
                    .($firmaMock ? ' · MOCK activo (no usa firmador real).' : '.').' Probalo en vivo con el botón.',
            ],
            [
                'clave' => 'internet', 'label' => 'Internet / conexión a Hacienda', 'estado' => 'info',
                'valor' => 'verificación manual',
                'detalle' => 'Confirmá conexión estable antes de emitir (no se prueba automáticamente en esta pantalla).',
            ],
            [
                'clave' => 'smtp', 'label' => 'Correo (SMTP)', 'estado' => $smtpConfigurado ? 'ok' : 'advertencia',
                'valor' => $mailer, 'detalle' => $smtpConfigurado
                    ? 'Remitente '.$mailFrom.($mailer === 'smtp' ? ' · host '.$mailHost : ' · driver '.$mailer).'.'
                    : 'Falta configurar el remitente/host de correo (no bloquea la emisión).',
            ],
        ];
    }

    /**
     * Correlativo de PRODUCCIÓN del SISTEMA NUEVO (punto de venta predeterminado, hoy
     * P002) por cada tipo de DTE, más el último CCF real aceptado. SOLO LECTURA: no
     * reserva ni incrementa nada.
     *
     * Conta Portable (P001) es una CONTINGENCIA INDEPENDIENTE: se muestra aparte, solo
     * informativa, y ya NO se compara ni se exige "alinear" contra el sistema nuevo.
     *
     * @return array<string, mixed>
     */
    private function correlativo(): array
    {
        $ultimo = Dte::where('ambiente', AmbienteHacienda::Produccion->value)
            ->where('estado', EstadoDte::Aceptado->value)
            ->whereNotNull('sello_recepcion')
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->first();

        $resuelto = CorrelativoSistemaNuevo::establecimientoYPuntoVenta();
        $establecimiento = $resuelto['establecimiento_id'] ? Establecimiento::find($resuelto['establecimiento_id']) : null;
        $puntoVenta = $resuelto['punto_venta_id'] ? PuntoVenta::find($resuelto['punto_venta_id']) : null;

        $tipos = [
            TipoDte::Factura->value => 'Factura',
            TipoDte::CreditoFiscal->value => 'Crédito Fiscal (CCF)',
            TipoDte::NotaCredito->value => 'Nota de Crédito',
            TipoDte::FacturaExportacion->value => 'Factura de Exportación',
        ];
        $proximos = [];
        foreach ($tipos as $tipo => $label) {
            $corr = CorrelativoSistemaNuevo::correlativo($tipo, AmbienteHacienda::Produccion->value);
            $proximos[$tipo] = [
                'label' => $label,
                'ultimo' => $corr?->ultimo_numero,
                'proximo' => $corr ? $corr->ultimo_numero + 1 : null,
            ];
        }

        // Último CCF real EXTERNO confirmado en Conta Portable — dato puramente
        // informativo (contingencia P001), NO participa en ningún cálculo de readiness.
        $externoUltimo = (int) (Configuracion::get('produccion.ultimo_ccf_externo') ?? 1093);

        return [
            'ultimo' => $ultimo ? [
                'numero_control' => $ultimo->numero_control,
                'fecha' => optional($ultimo->fecha_emision)->format('d/m/Y'),
                'total' => number_format((float) $ultimo->total_pagar, 2),
                // El sello de recepción es público (no secreto); se muestra abreviado.
                'sello' => $ultimo->sello_recepcion ? mb_substr((string) $ultimo->sello_recepcion, 0, 20).'…' : null,
            ] : null,
            'sistema_nuevo' => [
                'establecimiento' => $establecimiento?->codigo ?? '—',
                'punto_venta' => $puntoVenta?->codigo ?? '—',
                'proximos' => $proximos,
            ],
            'conta' => [
                'establecimiento' => 'M001',
                'punto_venta' => 'P001',
                'ultimo_ccf_externo' => $externoUltimo,
            ],
        ];
    }

    /**
     * Último backup registrado en `respaldo_ejecuciones` (backup diario verificado:
     * mysqldump + SHA-256). SOLO LECTURA de BD: no genera nada.
     *
     * @return array<string, mixed>
     */
    private function ultimoBackup(): array
    {
        $ultimo = RespaldoEjecucion::ultima();
        $esHoy = RespaldoEjecucion::hayValidoHoy();

        if ($ultimo === null) {
            return [
                'existe' => false,
                'estado' => 'critico',
                'es_hoy' => false,
                'detalle' => 'Todavía no se registró ningún backup diario. Generá uno antes de emitir real.',
            ];
        }

        return [
            'existe' => true,
            // Rojo (crítico) si NO hay un backup válido de HOY, aunque exista uno viejo.
            'estado' => $esHoy ? 'ok' : 'critico',
            'es_hoy' => $esHoy,
            'nombre' => $ultimo->archivo_ruta,
            'fecha' => optional($ultimo->terminado_en)->format('d/m/Y H:i'),
            'tamano' => $ultimo->archivo_tamano_bytes ? $this->humano($ultimo->archivo_tamano_bytes) : null,
            'sha256' => $ultimo->sha256,
            'exitoso' => (bool) $ultimo->exitoso,
            'detalle' => $esHoy
                ? 'Backup de HOY disponible y verificado (sha256 registrado).'
                : ($ultimo->exitoso
                    ? 'El último backup exitoso NO es de hoy: generá uno del día antes de emitir real.'
                    : 'El último backup registrado FALLÓ ('.$ultimo->mensaje.'): generá uno nuevo antes de emitir real.'),
        ];
    }

    /**
     * HIGIENE de configuración para "paralelo limpio": SOLO REPORTE. Lista flags que
     * conviene cerrar antes de operar en paralelo seguro. NO lee secretos (solo
     * booleanos) y NO modifica .env ni nada: es informativo. El operador decide.
     *
     * @return array<int, array{clave: string, label: string, actual: string, recomendado: string, ok: bool, motivo: string, env: string}>
     */
    private function higiene(): array
    {
        $modoParalelo = strtolower(trim((string) config('dte.transmision.modo_operacion', 'paralelo'))) === 'paralelo';

        $firmaEnabled = (bool) config('dte.firma.enabled', false);
        $firmaMock = (bool) config('dte.firma.mock', false);
        $invalConfirm = (bool) config('dte.invalidacion.real_confirmation', false);
        $invalMock = (bool) config('dte.invalidacion.mock', false);
        $transEnabled = (bool) config('dte.transmision.enabled', false);

        $items = [];

        // Firma local real habilitada mientras se opera en paralelo (residual de una emisión).
        $items[] = [
            'clave' => 'firma_enabled',
            'label' => 'Firma local real',
            'actual' => $firmaEnabled ? ($firmaMock ? 'habilitada (mock)' : 'HABILITADA (real)') : 'deshabilitada',
            'recomendado' => 'deshabilitada en paralelo',
            'ok' => ! ($firmaEnabled && ! $firmaMock && $modoParalelo),
            'motivo' => 'Con firma real habilitada en modo paralelo, un firmar/transmitir usaría el firmador real. En paralelo limpio conviene apagarla.',
            'env' => 'DTE_FIRMA_ENABLED',
        ];

        // Candado de invalidación real abierto (apitest/consola), conviene cerrarlo por higiene.
        $items[] = [
            'clave' => 'invalidacion_real',
            'label' => 'Confirmación de invalidación real',
            'actual' => $invalConfirm ? 'abierta' : 'cerrada',
            'recomendado' => 'cerrada',
            'ok' => ! ($invalConfirm && ! $invalMock),
            'motivo' => 'La confirmación de invalidación real está abierta. Solo afecta apitest por consola, pero conviene cerrarla por higiene.',
            'env' => 'DTE_INVALIDACION_REAL_CONFIRMATION',
        ];

        // Transmisión habilitada (aunque quede neutralizada por mock/dry-run/paralelo): informativo.
        $items[] = [
            'clave' => 'transmision_enabled',
            'label' => 'Transmisión habilitada (interruptor)',
            'actual' => $transEnabled ? 'encendido' : 'apagado',
            'recomendado' => 'informativo',
            'ok' => true,
            'motivo' => 'El interruptor puede estar encendido sin riesgo: los candados (mock, dry-run y paralelo) siguen bloqueando la transmisión real.',
            'env' => 'DTE_TRANSMISION_ENABLED',
        ];

        return $items;
    }

    private function humano(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }

    /** Recorta la salida larga de un comando para un mensaje flash. */
    private function cola(string $texto): string
    {
        $texto = trim($texto);

        return mb_strlen($texto) > 300 ? '…'.mb_substr($texto, -300) : $texto;
    }
}
