<?php

namespace App\Http\Controllers\Facturacion;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Http\Controllers\Controller;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteTransmisionService;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
     * Genera un backup SOLO de base de datos antes de emitir (spatie/laravel-backup).
     * Es admin-only y deliberadamente inofensivo: `--only-db` (no zipea la app) y
     * `--disable-notifications` (NO envía ningún correo). No emite ni transmite nada
     * ni toca correlativos. Sincrónico para no depender del worker.
     */
    public function backup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('administrador'), 403);

        try {
            $codigo = Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);
            $salida = trim((string) Artisan::output());

            if ($codigo === 0) {
                return back()->with('status', 'Backup de base de datos generado. Verificá el "último backup" abajo.');
            }

            return back()->with('error', 'El backup terminó con código '.$codigo.'. Revisá scripts/backup-run.bat. Detalle: '.$this->cola($salida));
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo generar el backup de BD: '.$e->getMessage()
                .' Podés usar scripts/backup-run.bat manualmente. No se emitió ni transmitió nada.');
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
     * Correlativo de PRODUCCIÓN: último CCF real aceptado por el MH y próximo número
     * esperado. SOLO LECTURA: no reserva ni incrementa nada.
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

        $corr = Correlativo::where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('ambiente', AmbienteHacienda::Produccion->value)
            ->where('activo', true)
            ->first();

        return [
            'ultimo' => $ultimo ? [
                'numero_control' => $ultimo->numero_control,
                'fecha' => optional($ultimo->fecha_emision)->format('d/m/Y'),
                'total' => number_format((float) $ultimo->total_pagar, 2),
                // El sello de recepción es público (no secreto); se muestra abreviado.
                'sello' => $ultimo->sello_recepcion ? mb_substr((string) $ultimo->sello_recepcion, 0, 20).'…' : null,
            ] : null,
            'proximo' => $corr ? [
                'serie' => $corr->serie,
                'ultimo_numero' => $corr->ultimo_numero,
                'siguiente' => $corr->siguiente_numero,
            ] : null,
        ];
    }

    /**
     * Último backup encontrado (spatie deja los .zip en storage/app/private/{name}).
     * SOLO LECTURA de disco: no genera nada.
     *
     * @return array<string, mixed>
     */
    private function ultimoBackup(): array
    {
        $nombre = (string) config('backup.backup.name', config('app.name'));
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$nombre);
        $rutaMostrada = 'storage/app/private/'.$nombre;

        if (File::isDirectory($dir)) {
            $zips = collect(File::files($dir))
                ->filter(fn ($f) => strtolower($f->getExtension()) === 'zip')
                ->sortByDesc(fn ($f) => $f->getMTime())
                ->values();
            if ($zips->isNotEmpty()) {
                $f = $zips->first();
                $fecha = Carbon::createFromTimestamp($f->getMTime());
                $reciente = $fecha->gt(now()->subDay());

                return [
                    'ruta' => $rutaMostrada,
                    'existe' => true,
                    'estado' => $reciente ? 'ok' : 'advertencia',
                    'nombre' => $f->getFilename(),
                    'fecha' => $fecha->format('d/m/Y H:i'),
                    'tamano' => $this->humano($f->getSize()),
                    'detalle' => $reciente ? 'Backup reciente (menos de 1 día).' : 'El último backup tiene más de 1 día.',
                ];
            }
        }

        return [
            'ruta' => $rutaMostrada,
            'existe' => false,
            'estado' => 'advertencia',
            'detalle' => 'No se encontró ningún backup en '.$rutaMostrada.'.',
        ];
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
