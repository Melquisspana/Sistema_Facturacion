<?php

namespace App\Mail;

use App\Models\DocumentoRecibido;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de una COMPRA (documento recibido) a contabilidad, para registro contable.
 * Adjunta los archivos ORIGINALES ya guardados del correo del proveedor (PDF, JSON y
 * otros adjuntos fiscales), tal como llegaron: no se regenera ni se convierte nada.
 *
 * Deliberadamente separado de DteCorreo (ventas): otro asunto, otro cuerpo y otros
 * adjuntos. No toca Hacienda ni el buzón de origen.
 */
class DocumentoRecibidoContabilidadCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{contenido: string, nombre: string, mime: string}>  $archivos  adjuntos ya leídos de disco
     * @param  array<int, string>  $omitidos  nombres omitidos por el límite de tamaño
     */
    public function __construct(
        public DocumentoRecibido $documento,
        public array $archivos,
        public array $omitidos = [],
    ) {}

    public function envelope(): Envelope
    {
        $num = $this->documento->numero_control;

        return new Envelope(
            subject: 'Documento recibido para contabilidad — '.$this->documento->tipoLabel().($num ? ' '.$num : ''),
        );
    }

    public function content(): Content
    {
        // Ojo: las claves NO pueden llamarse igual que las propiedades públicas
        // (`archivos`, `omitidos`), porque Mailable las inyecta en la vista y ganarían
        // sobre estos valores ya formateados.
        return new Content(markdown: 'emails.documento-recibido-contabilidad', with: [
            'listaAdjuntos' => implode(', ', array_map(fn (array $a) => $a['nombre'], $this->archivos)),
            'listaOmitidos' => implode(', ', $this->omitidos),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromData(fn () => $a['contenido'], $a['nombre'])->withMime($a['mime']),
            $this->archivos,
        );
    }
}
