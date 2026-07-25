<?php

namespace App\Jobs;

use App\Mail\DocumentoRecibidoContabilidadCorreo;
use App\Models\DocumentoRecibidoEnvio;
use App\Models\User;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Envía a contabilidad UN documento recibido (compra) con sus adjuntos originales ya
 * guardados. Se ENCOLA para no bloquear la interfaz con la latencia del SMTP. Toma un
 * registro `DocumentoRecibidoEnvio` en 'pendiente' y lo deja 'enviado', 'simulado'
 * (mailer no real) o 'error'.
 *
 * Solo cuando termina en 'enviado' marca el documento como enviado a contabilidad, y
 * solo si estaba 'pendiente' (nunca reactiva un 'ignorado'). NO lee ni modifica el
 * buzón Yahoo, no descarga adjuntos y no toca DTE emitidos ni correlativos.
 */
class EnviarDocumentoRecibidoContabilidad implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $envioId) {}

    public function handle(AdjuntosDocumentoRecibido $adjuntos): void
    {
        $envio = DocumentoRecibidoEnvio::with('documento')->find($this->envioId);
        if (! $envio || $envio->estado === 'enviado') {
            return;
        }

        $documento = $envio->documento;
        $destinatarios = $envio->listaDestinatarios();
        if ($documento === null || $destinatarios === []) {
            $this->marcarError($envio, 'Documento o destinatarios no disponibles.');

            return;
        }

        $seleccion = $adjuntos->seleccionar($documento);
        if ($seleccion['enviados'] === []) {
            // Sin ningún archivo válido no se manda un correo vacío a contabilidad.
            $this->marcarError($envio, 'El documento no tiene adjuntos guardados para enviar.');
            $this->auditar($envio);

            return;
        }

        $omitidos = array_map(fn (array $a) => $a['nombre'], $seleccion['omitidos']);

        try {
            $archivos = array_map(fn (array $a) => [
                'contenido' => (string) Storage::disk('local')->get($a['ruta']),
                'nombre' => $a['nombre'],
                'mime' => $a['mime'],
            ], $seleccion['enviados']);

            Mail::to($destinatarios)->send(new DocumentoRecibidoContabilidadCorreo($documento, $archivos, $omitidos));

            // Si el mailer activo NO es real (log/array) el correo no salió por SMTP: se
            // marca SIMULADO, que NO cuenta como enviado (el documento sigue pendiente).
            $real = $this->mailerEsReal();
            $envio->update([
                'estado' => $real ? 'enviado' : 'simulado',
                'adjuntos' => $adjuntos->nombres($seleccion['enviados']),
                'adjuntos_omitidos' => $omitidos === [] ? null : implode(', ', $omitidos),
                'error' => $real ? null : 'Correo NO enviado realmente: MAIL_MAILER='.config('mail.default').' (driver no SMTP; el correo se escribió en laravel.log).',
            ]);

            if ($real && $documento->estado === 'pendiente') {
                // Único camino que marca la compra como enviada: un envío EXITOSO.
                $documento->update(['estado' => 'enviado']);
            }

            $this->auditar($envio);
        } catch (\Throwable $e) {
            // El error queda registrado; el reintento es manual (no auto-retry).
            $this->marcarError($envio, $e->getMessage());
            $this->auditar($envio);
        }
    }

    /** Si el job falla de forma fatal (deserialización, timeout duro), deja el error. */
    public function failed(\Throwable $e): void
    {
        $envio = DocumentoRecibidoEnvio::find($this->envioId);
        if ($envio && $envio->estado !== 'enviado') {
            $this->marcarError($envio, $e->getMessage());
        }
    }

    private function marcarError(DocumentoRecibidoEnvio $envio, string $error): void
    {
        $envio->update(['estado' => 'error', 'error' => mb_substr($error, 0, 1000)]);
    }

    /**
     * ¿El mailer activo envía DE VERDAD por SMTP? Los drivers `log` y `array` NO envían:
     * escriben/descartan. Misma regla que el envío de ventas, para no mentir en el historial.
     */
    private function mailerEsReal(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.$mailer.transport", $mailer);

        return ! in_array($transport, ['log', 'array'], true);
    }

    private function auditar(DocumentoRecibidoEnvio $envio): void
    {
        $mensaje = match ($envio->estado) {
            'enviado' => 'envió una compra a contabilidad por correo',
            'simulado' => 'registró envío SIMULADO de una compra a contabilidad (mailer no real, no salió por SMTP)',
            default => 'falló el envío de una compra a contabilidad',
        };

        activity('documento_recibido_correo')
            ->performedOn($envio->documento)
            ->causedBy($envio->user_id ? User::find($envio->user_id) : null)
            ->withProperties([
                'envio_id' => $envio->id,
                'destinatarios' => implode(', ', $envio->listaDestinatarios()),
                'estado' => $envio->estado,
                'adjuntos' => $envio->adjuntos,
                'adjuntos_omitidos' => $envio->adjuntos_omitidos,
                'error' => $envio->error,
            ])
            ->log($mensaje);
    }
}
