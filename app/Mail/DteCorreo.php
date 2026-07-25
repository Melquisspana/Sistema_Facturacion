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
 * Correo con la representación gráfica del DTE (PDF) y, si existen, el JSON oficial y
 * el JWS firmado. Los adjuntos son los MISMOS para todos los destinatarios; lo único
 * que cambia por canal es el asunto y el cuerpo:
 *
 * - canal `cliente` (o null: envíos históricos) → texto al cliente con la plantilla
 *   configurable ({@see \App\Support\Dte\PlantillaCorreo});
 * - canal `contabilidad` → aviso interno de registro contable, sin textos dirigidos
 *   al cliente y sin plantilla.
 *
 * No toca Hacienda: solo manda lo ya generado/firmado/aceptado.
 */
class DteCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $pdfBytes  contenido del PDF ya renderizado
     * @param  array<int, array{contenido: string, nombre: string, mime: string}>  $adjuntosExtra  JSON/JWS opcionales
     * @param  ?string  $plantilla  plantilla del cuerpo (con variables {{...}}); null = default. Solo canal cliente.
     * @param  ?string  $canal  DteEnvio::CANAL_* del envío; null = histórico (se trata como cliente)
     */
    public function __construct(
        public Dte $dte,
        public string $pdfBytes,
        public array $adjuntosExtra = [],
        public ?string $plantilla = null,
        public ?string $canal = null,
    ) {}

    public function envelope(): Envelope
    {
        $tipo = $this->dte->tipo_dte->label();
        $num = $this->dte->numero_control ?: $this->dte->numero_interno;

        if ($this->esParaContabilidad()) {
            return new Envelope(subject: 'DTE para contabilidad — '.$tipo.($num ? ' '.$num : ''));
        }

        return new Envelope(subject: $tipo.($num ? ' '.$num : '').' — Dulces La Negrita');
    }

    public function content(): Content
    {
        if ($this->esParaContabilidad()) {
            return new Content(markdown: 'emails.dte-contabilidad', with: [
                'dte' => $this->dte,
                'adjuntos' => $this->listaAdjuntos(),
            ]);
        }

        return new Content(markdown: 'emails.dte', with: [
            'dte' => $this->dte,
            'cuerpo' => \App\Support\Dte\PlantillaCorreo::render($this->plantilla, $this->dte),
            'aceptado' => $this->dte->estado === \App\Enums\EstadoDte::Aceptado,
        ]);
    }

    /** ¿Este correo va al canal de contabilidad (aviso interno) y no al cliente? */
    private function esParaContabilidad(): bool
    {
        return $this->canal === \App\Models\DteEnvio::CANAL_CONTABILIDAD;
    }

    /**
     * Adjuntos REALES en texto ("PDF y JSON oficiales", "PDF"): no promete un JSON que
     * no viaja (un DTE sin json_generado_path se envía solo con el PDF).
     */
    private function listaAdjuntos(): string
    {
        $tieneJson = collect($this->adjuntosExtra)
            ->contains(fn (array $a) => str_ends_with(strtolower($a['nombre']), '.json'));

        return $tieneJson ? 'PDF y JSON oficiales' : 'PDF';
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
