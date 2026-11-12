<?php

namespace App\Mail\Pedidos;

use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo de las 18:00, el cierre del día para la dueña: lo que hizo la fábrica con los
 * pedidos de sala, lo vendido en el día en una línea (el total, no cada CCF) y, adjunto, el
 * PDF del día de cada vendedor. LLEVA DINERO (ventas y cobros): va solo a quien esté en
 * Configuración › Rutas. No es un documento fiscal. Todo lo arma {@see ResumenDiarioPedidos}.
 *
 * @param  array<string, mixed>  $contenido
 * @param  array<string, string>  $pdf  nombre de archivo => contenido del PDF
 */
class ResumenDiarioPedidosCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $contenido, public array $pdf = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pedidos del '.$this->contenido['fecha']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.pedidos-resumen-diario', with: [
            'contenido' => $this->contenido,
            'literal' => self::literal(...),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (string $archivo) => Attachment::fromData(fn () => $this->pdf[$archivo], $archivo)->withMime('application/pdf'),
            array_keys($this->pdf),
        );
    }

    /**
     * Lo que escribió alguien (sala, producto, motivo) se lee tal cual: `{{ }}` solo escapa
     * `<`, `>` y `&`, y markdown convertiría `[texto](url)` o `![](url)` en un enlace o una
     * imagen externa dentro de un correo del sistema. Se escapa con barra la puntuación de
     * markdown y se junta todo en una línea (un salto permitiría un título o una referencia
     * de enlace). `<`, `>` y `&` los sigue escapando `{{ }}`.
     */
    public static function literal(?string $texto): string
    {
        $linea = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        return (string) preg_replace('/[\\\\`*_\[\]()!#+\-.|~{}]/', '\\\\$0', $linea);
    }
}
