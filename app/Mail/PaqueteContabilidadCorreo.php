<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo del PAQUETE mensual para contabilidad (herramienta interna: la contadora no
 * entra al sistema). Lleva el mismo ZIP que genera el paquete mensual adjunto y un
 * resumen del periodo. Envío MANUAL y solo tras confirmación con frase exacta.
 *
 * NO tiene nada que ver con DTE emitidos, correlativos, firmador ni transmisión a
 * Hacienda; no es el correo al cliente. Solo empaqueta lo ya generado localmente.
 *
 * @param  array{compras_cantidad:int, compras_total:float, ventas_cantidad:int, ventas_total:float, desde:string, hasta:string, incluir_compras:bool, incluir_ventas:bool}  $resumen
 */
class PaqueteContabilidadCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $etiqueta,
        public string $zipBytes,
        public string $nombreZip,
        public array $resumen,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Paquete contabilidad Dulces La Negrita - '.$this->etiqueta);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.paquete-contabilidad', with: [
            'etiqueta' => $this->etiqueta,
            'resumen' => $this->resumen,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->zipBytes, $this->nombreZip)->withMime('application/zip'),
        ];
    }
}
