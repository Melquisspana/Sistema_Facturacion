<?php

namespace App\Mail\Pedidos;

use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El resumen de las 17:00 de lo que hizo la fábrica con los pedidos de sala. Es un aviso
 * interno: sin adjuntos, sin precios y sin nada fiscal. El contenido lo arma
 * {@see ResumenDiarioPedidos}.
 *
 * @param  array<string, mixed>  $contenido
 */
class ResumenDiarioPedidosCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $contenido) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pedidos del '.$this->contenido['fecha']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.pedidos-resumen-diario', with: ['contenido' => $this->contenido]);
    }
}
