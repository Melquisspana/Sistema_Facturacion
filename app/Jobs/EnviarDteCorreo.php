<?php

namespace App\Jobs;

use App\Mail\DteCorreo;
use App\Models\Configuracion;
use App\Models\DteEnvio;
use App\Models\User;
use App\Services\Dte\DtePdfService;
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

            Mail::to($destinatarios)->send(new DteCorreo($dte, $bytes, $extra, $plantilla));

            // Si el mailer activo NO es real (log/array), el correo NO sale por SMTP: se marca
            // como SIMULADO (no "enviado"), para no mentir en el historial. Los adjuntos se
            // generan igual y, con el driver log, el correo queda escrito en laravel.log.
            $real = $this->mailerEsReal();
            $envio->update([
                'estado' => $real ? 'enviado' : 'simulado',
                'adjuntos' => implode(', ', array_merge(['PDF'], $nombres)),
                'error' => $real ? null : 'Correo NO enviado realmente: MAIL_MAILER='.config('mail.default').' (driver no SMTP; el correo se escribió en laravel.log).',
            ]);
            $this->auditar($envio);
        } catch (\Throwable $e) {
            // El error SMTP queda registrado; el reenvío es manual (no auto-retry).
            $this->marcarError($envio, $e->getMessage());
            $this->auditar($envio);
        }
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

    /**
     * ¿El mailer activo envía DE VERDAD por SMTP (u otro transporte real)? Los drivers `log`
     * y `array` NO envían: escriben/descartan. Se usa para no marcar "enviado" en modo prueba.
     */
    private function mailerEsReal(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.$mailer.transport", $mailer);

        return ! in_array($transport, ['log', 'array'], true);
    }

    private function auditar(DteEnvio $envio): void
    {
        $mensaje = match ($envio->estado) {
            'enviado' => 'envió el DTE por correo',
            'simulado' => 'registró envío SIMULADO del DTE (mailer no real, no salió por SMTP)',
            default => 'falló el envío del DTE por correo',
        };

        activity('dte_correo')
            ->performedOn($envio->dte)
            ->causedBy($envio->user_id ? User::find($envio->user_id) : null)
            ->withProperties([
                'envio_id' => $envio->id,
                'destinatarios' => $envio->destinatariosTexto(),
                'estado' => $envio->estado,
                'auto' => $envio->user_id === null,
            ])
            ->log($mensaje);
    }
}
