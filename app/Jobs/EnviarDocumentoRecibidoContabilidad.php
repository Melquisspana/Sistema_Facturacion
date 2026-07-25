<?php

namespace App\Jobs;

use App\Mail\DocumentoRecibidoContabilidadCorreo;
use App\Models\DocumentoRecibidoEnvio;
use App\Models\User;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use App\Support\Correo\CandadoCorreoReal;
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
        // Candado de correo real (política del entorno, no un colaborador intercambiable).
        $candado = app(CandadoCorreoReal::class);

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

        // CANDADO de correo real: fuera de producción (o con un mailer de prueba) NO se
        // llama al transporte. Se registra SIMULADO y el documento SIGUE PENDIENTE.
        $simular = $candado->debeSimular();

        try {
            $archivos = array_map(fn (array $a) => [
                'contenido' => (string) Storage::disk('local')->get($a['ruta']),
                'nombre' => $a['nombre'],
                'mime' => $a['mime'],
            ], $seleccion['enviados']);

            if (! $simular) {
                Mail::to($destinatarios)->send(new DocumentoRecibidoContabilidadCorreo($documento, $archivos, $omitidos));
            }

            $real = ! $simular;
            $envio->update([
                'estado' => $real ? 'enviado' : 'simulado',
                'adjuntos' => $adjuntos->nombres($seleccion['enviados']),
                'adjuntos_omitidos' => $omitidos === [] ? null : implode(', ', $omitidos),
                'error' => $real ? null : $candado->motivo(),
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

    private function auditar(DocumentoRecibidoEnvio $envio): void
    {
        $mensaje = match ($envio->estado) {
            'enviado' => 'envió una compra a contabilidad por correo',
            'simulado' => 'registró envío SIMULADO de una compra a contabilidad (candado de correo real: no salió por SMTP)',
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
