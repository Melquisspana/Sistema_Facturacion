<?php

namespace App\Jobs;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Mail\PaqueteContabilidadCorreo;
use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\Contabilidad\AuditoriaPaquete;
use App\Services\Contabilidad\CoberturaPaquete;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Services\Contabilidad\PermisoDriveFaltante;
use App\Services\Contabilidad\SubidaDrivePaqueteContrato;
use App\Support\Correo\CandadoCorreoReal;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Arma el paquete y lo envía a contabilidad en segundo plano. Las guardas (frase
 * exacta, correo válido, documentos en el rango, cobertura) ya se revisaron en la
 * petición; acá se hace lo que antes hacía `PaqueteContabilidadController::enviar()`
 * después de ellas, que con un mes completo pasaba de los 100 s de Cloudflare.
 *
 * Respeta el CANDADO de correo real ({@see CandadoCorreoReal}): fuera de producción no
 * se llama al transporte, se audita como simulado y no se marca nada. NO toca DTE
 * emitidos, correlativos, firmador, transmisión ni el buzón. Si el envío termina
 * EXITOSO, marca como "enviado" solo las compras incluidas que estaban "pendiente".
 *
 * Un solo intento: reintentar solo podría mandar el correo dos veces.
 */
class EnviarPaqueteContabilidad implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    /** @param  array{desde: string, hasta: string, etiqueta: string, mes: int, anio: int}  $rango */
    public function __construct(
        public int $usuarioId,
        public string $correo,
        public array $rango,
        public bool $incluirCompras,
        public bool $incluirVentas,
    ) {}

    public function handle(PaqueteContabilidadZip $zip, PeriodoPaquete $periodo, CoberturaPaquete $cobertura, AuditoriaPaquete $auditoria, SubidaDrivePaqueteContrato $drive): void
    {
        $usuario = User::find($this->usuarioId);
        $estado = $this->estado();
        $compras = $this->incluirCompras ? $periodo->compras($this->rango) : new Collection;
        $ventas = $this->incluirVentas ? $periodo->ventas($this->rango) : new Collection;
        $cob = $this->incluirCompras ? $cobertura->para($this->rango['desde'], $this->rango['hasta']) : null;

        $resumen = [
            'compras_cantidad' => $compras->count(),
            'compras_total' => round((float) $compras->sum('total'), 2),
            'ventas_cantidad' => $ventas->count(),
            'ventas_total' => round((float) $ventas->sum('total_pagar'), 2),
            'desde' => $this->rango['desde'],
            'hasta' => $this->rango['hasta'],
            'incluir_compras' => $this->incluirCompras,
            'incluir_ventas' => $this->incluirVentas,
        ];

        $r = $zip->generar($this->rango['etiqueta'], $compras, $ventas, $this->incluirCompras, $this->incluirVentas, $cob);
        $nombreZip = $zip->nombreArchivo($this->rango['etiqueta'], $r['incompleto']);

        // CANDADO de correo real: fuera de producción NO se llama al transporte. Se audita
        // como 'simulado' y NO se marca ninguna compra como enviada. El ZIP se generó
        // igual, así que el ensayo es realista.
        $candado = app(CandadoCorreoReal::class);
        if ($candado->debeSimular()) {
            $auditoria->registrar($usuario, 'simulado', $this->correo, $this->rango, $resumen, $nombreZip, $candado->motivo(), 0);
            @unlink($r['ruta']);
            $estado->terminar('simulado', "Paquete {$this->rango['etiqueta']} NO enviado: ".$candado->motivo()
                .' Se registró como simulado y no se marcó ninguna compra como enviada.');

            return;
        }

        $archivoDrive = null;
        $subido = false;
        try {
            $archivoDrive = $drive->subir($r['ruta'], $nombreZip, $this->rango['anio'], $this->rango['mes'], $this->correo);
            $subido = true;

            // Configuración de correo vigente antes de construir el transporte. En la cola
            // también la aplica el listener de JobProcessing; pedirla acá no cuesta nada
            // y cubre el driver sync.
            app(ConfiguracionCorreoRuntime::class)->aplicar();

            Mail::to($this->correo)->send(new PaqueteContabilidadCorreo($this->rango['etiqueta'], $archivoDrive['webViewLink'], $nombreZip, $resumen));
        } catch (Throwable $e) {
            // Falla: no cambia estados; el ZIP queda en paquetes/ (se limpia a las 24 h)
            // por si hay que revisarlo; registra auditoría "fallido".
            $mensaje = $e instanceof PermisoDriveFaltante
                ? 'Falta autorizar Drive en Configuración → Integraciones.'
                : ($subido ? 'No se pudo enviar el correo con el enlace de Drive. Reintentá el envío.' : 'No se pudo subir o compartir el ZIP en Drive. Revisá la autorización y que la Google Drive API esté habilitada; después reintentá.');
            // El motivo técnico (saneado) va al log y a la auditoría: sin él, un 403 de
            // Google se ve igual que una red caída y no hay por dónde empezar.
            $motivo = self::motivoTecnico($e);
            Log::warning('Paquete de contabilidad: falló el envío.', [
                'etapa' => $subido ? 'correo' : 'drive',
                'etiqueta' => $this->rango['etiqueta'],
                'motivo' => $motivo,
            ]);
            $auditoria->registrar($usuario, 'fallido', $this->correo, $this->rango, $resumen, $nombreZip, $mensaje, archivoDriveId: $archivoDrive['id'] ?? null, errorTecnico: $motivo);
            GenerarPaqueteContabilidad::guardarZip($r['ruta'], $estado->rutaZip());
            $google = $e instanceof GoogleServiceException ? self::codigoGoogle($e) : null;
            $estado->terminar('envio_fallido', 'No se pudo enviar el paquete a contabilidad: '.$mensaje
                .($google ? " (Google respondió {$google})" : '').' (no se cambió ningún estado).');

            return;
        }

        // Éxito: marca como "enviado" solo las compras incluidas que estaban "pendiente"
        // (no toca "ignorado" ni las ya "enviado"). Las ventas/DTE no se tocan nunca.
        $marcadas = 0;
        if ($this->incluirCompras && $compras->isNotEmpty()) {
            $marcadas = DocumentoRecibido::whereIn('id', $compras->pluck('id'))
                ->where('estado', 'pendiente')
                ->update(['estado' => 'enviado']);
        }

        $auditoria->registrar($usuario, 'enviado', $this->correo, $this->rango, $resumen, $nombreZip, null, $marcadas, archivoDriveId: $archivoDrive['id']);
        @unlink($r['ruta']);

        $estado->terminar('enviado', "Paquete {$this->rango['etiqueta']} enviado a {$this->correo} ({$resumen['compras_cantidad']} compras, {$resumen['ventas_cantidad']} ventas). {$marcadas} compra(s) marcada(s) como enviada(s). Las ventas no se modificaron.");
    }

    public function failed(Throwable $e): void
    {
        $this->estado()->terminar('envio_fallido', 'No se pudo preparar el paquete para enviarlo (no se envió nada ni se cambió ningún estado). Reintentá el envío.');
    }

    /** Tope del motivo técnico que se guarda en el log y la auditoría. */
    private const MAX_MOTIVO = 500;

    /**
     * Clase y mensaje de la excepción (y de su causa, si la hay), en una línea, sin
     * credenciales y acotado. De un error HTTP de Google toma el código y el «reason»
     * (403 accessNotConfigured, insufficientPermissions, storageQuotaExceeded…) en vez
     * del cuerpo JSON crudo.
     */
    public static function motivoTecnico(Throwable $e): string
    {
        $partes = [];
        for ($actual = $e, $nivel = 0; $actual && $nivel < 3; $actual = $actual->getPrevious(), $nivel++) {
            $texto = $actual::class;
            if ($actual instanceof GoogleServiceException) {
                $texto .= ' HTTP '.self::codigoGoogle($actual);
                $detalle = $actual->getErrors()[0]['message'] ?? null;
                $texto .= ': '.(is_string($detalle) && $detalle !== '' ? $detalle : $actual->getMessage());
            } else {
                $texto .= ': '.$actual->getMessage();
            }
            $partes[] = $texto;
        }

        $motivo = preg_replace('/\s+/u', ' ', implode(' ← causa: ', $partes)) ?? '';

        return mb_substr(trim(self::sinCredenciales($motivo)), 0, self::MAX_MOTIVO);
    }

    /** «403 accessNotConfigured»: código HTTP y reason del primer error de Google. */
    private static function codigoGoogle(GoogleServiceException $e): string
    {
        $reason = $e->getErrors()[0]['reason'] ?? null;

        return trim($e->getCode().' '.(is_string($reason) ? preg_replace('/[^A-Za-z0-9_.-]/', '', $reason) : ''));
    }

    /** Tacha tokens, cabeceras Authorization y secretos que pudieran venir en el texto. */
    private static function sinCredenciales(string $texto): string
    {
        // \x27 es la comilla simple: así los patrones no chocan con la cadena PHP.
        return preg_replace([
            '~\bAuthorization\b\s*[:=]\s*(?:(?:Bearer|Basic)\s+)?[^\s,;"\x27}]+~i',
            '~\bBearer\s+[A-Za-z0-9\-._\~+/]+=*~i',
            '~(["\x27]?)\b(access_token|refresh_token|id_token|client_secret|token|code|key|password)\b\1\s*[:=]\s*["\x27]?[^\s"\x27&,;}]+["\x27]?~i',
            '~\bya29\.[A-Za-z0-9\-_.]+~',
            '~\b1//[A-Za-z0-9\-_]{10,}~',
        ], [
            'Authorization: [oculto]',
            'Bearer [oculto]',
            '$2=[oculto]',
            '[oculto]',
            '[oculto]',
        ], $texto) ?? '';
    }

    private function estado(): EstadoPaquete
    {
        return EstadoPaquete::para($this->usuarioId, 'envio', $this->rango, $this->incluirCompras, $this->incluirVentas);
    }
}
