<?php

namespace App\Jobs;

use App\Mail\DteCorreo;
use App\Models\Configuracion;
use App\Models\DteEnvio;
use App\Models\User;
use App\Services\Dte\DtePdfService;
use App\Support\Contabilidad\CorreoContabilidad;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Envía por correo un DTE (PDF + JSON + JWS opcional) a sus destinatarios. Se ENCOLA
 * para no bloquear la interfaz con la latencia del SMTP. Toma un registro `DteEnvio`
 * en estado 'pendiente' y lo deja 'enviado' o 'error' (con el error SMTP). Audita el
 * resultado. No transmite a Hacienda ni cambia el estado fiscal del DTE.
 */
class EnviarDteCorreo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $envioId) {}

    public function handle(DtePdfService $pdf): void
    {
        // Candado de correo real (política del entorno, no un colaborador intercambiable).
        $candado = app(CandadoCorreoReal::class);

        $envio = DteEnvio::with(['dte.cliente'])->find($this->envioId);
        if (! $envio || $envio->estado === 'enviado') {
            return;
        }

        $dte = $envio->dte;
        $destinatarios = $envio->destinatarios ?: array_values(array_filter([$envio->destinatario]));
        if ($dte === null || $destinatarios === []) {
            $this->marcarError($envio, 'Documento o destinatarios no disponibles.');

            return;
        }

        try {
            $bytes = $pdf->bytes($dte);
            [$extra, $nombres] = $this->adjuntos($dte);
            $plantilla = Configuracion::get('correo.plantilla');

            // CANDADO de correo real: fuera de producción (o con un mailer de prueba) NO se
            // llama al transporte. Se registra SIMULADO —el DTE conserva su estado— y el
            // resto del trabajo (PDF, JSON/JWS, historial, auditoría) corre igual, para que
            // el ensayo sea realista y cualquier fallo de armado siga saliendo como error.
            $simular = $candado->debeSimular();

            // Copia a contabilidad (BCC) DENTRO del mismo envío, solo si está activada
            // la preferencia y hay un correo válido configurado. No es un envío aparte
            // ni automático: viaja como copia oculta del correo que el usuario ya manda.
            // Si el envío YA va dirigido a contabilidad (canal contabilidad), no se
            // agrega la copia: sería el mismo correo dos veces al mismo destinatario.
            // Simulando no hay copia que registrar: nada viajó.
            $bccContabilidad = ($simular || $envio->esCanalContabilidad()) ? null : $this->correoContabilidad();

            if (! $simular) {
                $mail = Mail::to($destinatarios);
                if ($bccContabilidad !== null) {
                    $mail->bcc($bccContabilidad);
                }
                // El canal decide asunto y cuerpo (cliente vs contabilidad); los adjuntos son los mismos.
                $mail->send(new DteCorreo($dte, $bytes, $extra, $plantilla, $envio->canal));
            }

            $envio->update([
                'estado' => $simular ? 'simulado' : 'enviado',
                'adjuntos' => implode(', ', array_merge(['PDF'], $nombres)),
                'error' => $simular ? $candado->motivo() : null,
            ]);
            $this->auditar($envio, $bccContabilidad);
        } catch (\Throwable $e) {
            // El error SMTP queda registrado; el reenvío es manual (no auto-retry).
            $this->marcarError($envio, $e->getMessage());
            $this->auditar($envio);
        }
    }

    /**
     * Correo de contabilidad para la copia BCC, o null si no aplica. Solo devuelve
     * un correo cuando la preferencia está activa Y hay una dirección VÁLIDA. No
     * envía nada por sí mismo; solo resuelve el destinatario oculto.
     */
    private function correoContabilidad(): ?string
    {
        return app(CorreoContabilidad::class)->copiaOculta();
    }

    /** Si el job falla de forma fatal (deserialización, timeout duro), deja el error. */
    public function failed(\Throwable $e): void
    {
        $envio = DteEnvio::find($this->envioId);
        if ($envio && $envio->estado !== 'enviado') {
            $this->marcarError($envio, $e->getMessage());
        }
    }

    /**
     * Adjuntos extra: JSON oficial (si existe) y JWS firmado (si existe y está
     * habilitado en Configuración: correo.adjuntar_jws).
     *
     * @return array{0: array<int, array{contenido: string, nombre: string, mime: string}>, 1: array<int, string>}
     */
    private function adjuntos(\App\Models\Dte $dte): array
    {
        $disco = (string) config('dte.storage.disk', 'local');
        $extra = [];
        $nombres = [];

        if (filled($dte->json_generado_path) && Storage::disk($disco)->exists($dte->json_generado_path)) {
            $extra[] = ['contenido' => (string) Storage::disk($disco)->get($dte->json_generado_path), 'nombre' => 'dte-'.$dte->id.'.json', 'mime' => 'application/json'];
            $nombres[] = 'JSON';
        }
        if (Configuracion::getBool('correo.adjuntar_jws', false)
            && filled($dte->json_firmado_path) && Storage::disk($disco)->exists($dte->json_firmado_path)) {
            $extra[] = ['contenido' => (string) Storage::disk($disco)->get($dte->json_firmado_path), 'nombre' => 'dte-'.$dte->id.'.jws', 'mime' => 'application/jose'];
            $nombres[] = 'JWS';
        }

        return [$extra, $nombres];
    }

    private function marcarError(DteEnvio $envio, string $error): void
    {
        $envio->update(['estado' => 'error', 'error' => mb_substr($error, 0, 1000)]);
    }

    private function auditar(DteEnvio $envio, ?string $bccContabilidad = null): void
    {
        $mensaje = match ($envio->estado) {
            'enviado' => 'envió el DTE por correo',
            'simulado' => 'registró envío SIMULADO del DTE (candado de correo real: no salió por SMTP)',
            default => 'falló el envío del DTE por correo',
        };
        // Deja constancia en la auditoría de correo si viajó copia (BCC) a contabilidad.
        if ($bccContabilidad !== null && in_array($envio->estado, ['enviado', 'simulado'], true)) {
            $mensaje .= ' (con copia a contabilidad)';
        }

        activity('dte_correo')
            ->performedOn($envio->dte)
            ->causedBy($envio->user_id ? User::find($envio->user_id) : null)
            ->withProperties([
                'envio_id' => $envio->id,
                'destinatarios' => $envio->destinatariosTexto(),
                'estado' => $envio->estado,
                'auto' => $envio->user_id === null,
                'copia_contabilidad' => $bccContabilidad,
            ])
            ->log($mensaje);
    }
}
