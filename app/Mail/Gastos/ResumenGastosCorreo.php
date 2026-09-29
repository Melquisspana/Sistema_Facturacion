<?php

namespace App\Mail\Gastos;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Resumen periódico de lo que está pendiente de pago.
 *
 * Es un RECORDATORIO INTERNO, no un documento fiscal: no lleva adjuntos, no habla
 * de DTE y no se parece al correo al cliente ni al de contabilidad. Solo se arma
 * cuando hay pendientes reales, así que este mensaje nunca dice «no hay nada».
 *
 * El contenido llega ya recortado por el alcance de quien lo recibe: si no alcanza
 * los gastos personales, en este correo no hay ni una línea de ellos ni un total
 * del que se puedan deducir.
 *
 * @param  array<string, mixed>  $contenido
 */
class ResumenGastosCorreo extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombre,
        public array $contenido,
    ) {}

    public function envelope(): Envelope
    {
        $vencidos = count($this->contenido['vencidos'] ?? []);

        return new Envelope(
            subject: $vencidos > 0
                ? 'Gastos pendientes — '.$vencidos.' vencido'.($vencidos === 1 ? '' : 's')
                : 'Gastos pendientes',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.gastos-resumen', with: [
            'nombre' => $this->nombre,
            'contenido' => $this->contenido,
        ]);
    }
}
