<?php

namespace App\Mail;

use App\Models\Dte;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo al cliente con la representación gráfica del DTE (PDF) y, si existen, el
 * JSON oficial y el JWS firmado. Envío MANUAL y síncrono (no se encola). No toca
 * Hacienda: solo manda al cliente lo ya generado/firmado/aceptado.
 */
class DteCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $pdfBytes  contenido del PDF ya renderizado
     * @param  array<int, array{contenido: string, nombre: string, mime: string}>  $adjuntosExtra  JSON/JWS opcionales
     * @param  ?string  $plantilla  plantilla del cuerpo (con variables {{...}}); null = default
     */
    public function __construct(
        public Dte $dte,
        public string $pdfBytes,
        public array $adjuntosExtra = [],
        public ?string $plantilla = null,
    ) {}

    public function envelope(): Envelope
    {
        $tipo = $this->dte->tipo_dte->label();
        $num = $this->dte->numero_control ?: $this->dte->numero_interno;

        return new Envelope(subject: $tipo.($num ? ' '.$num : '').' — Dulces La Negrita');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.dte', with: [
            'dte' => $this->dte,
            'cuerpo' => \App\Support\Dte\PlantillaCorreo::render($this->plantilla, $this->dte),
            'aceptado' => $this->dte->estado === \App\Enums\EstadoDte::Aceptado,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $adjuntos = [
            Attachment::fromData(fn () => $this->pdfBytes, $this->nombrePdf())->withMime('application/pdf'),
        ];

        foreach ($this->adjuntosExtra as $a) {
            $adjuntos[] = Attachment::fromData(fn () => $a['contenido'], $a['nombre'])->withMime($a['mime']);
        }

        return $adjuntos;
    }

    private function nombrePdf(): string
    {
        $base = $this->dte->numero_control ?: ('DTE-'.$this->dte->id);

        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $base).'.pdf';
    }
}
