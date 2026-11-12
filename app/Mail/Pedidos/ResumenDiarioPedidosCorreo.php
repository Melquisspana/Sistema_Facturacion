<?php

namespace App\Mail\Pedidos;

use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El resumen de las 18:00 de lo que hizo la fábrica con los pedidos de sala. Es un aviso
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
        return new Content(markdown: 'emails.pedidos-resumen-diario', with: [
            'contenido' => $this->contenido,
            'literal' => self::literal(...),
        ]);
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
