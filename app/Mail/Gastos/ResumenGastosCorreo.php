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
            'detalle' => $this->detalle(),
        ]);
    }

    /**
     * Las filas que se MUESTRAN, recortadas al tope `gastos.avisos.max_filas_resumen`.
     *
     * El tope es para todo el correo, no por grupo, y se reparte en el orden en que se
     * lee: primero lo vencido, después lo próximo y al final lo que espera monto. Lo que
     * no entra no se pierde: los títulos y los totales siguen contando TODO (salen de
     * `contenido`, que no se recorta) y cada grupo dice cuántas filas le faltan.
     *
     * @return array{vencidos: array<int, array<string, mixed>>, proximos: array<int, array<string, mixed>>, sin_monto: array<int, array<string, mixed>>}
     */
    private function detalle(): array
    {
        $quedan = max(0, (int) config('gastos.avisos.max_filas_resumen'));
        $detalle = [];

        foreach (['vencidos', 'proximos', 'sin_monto'] as $grupo) {
            $detalle[$grupo] = array_slice($this->contenido[$grupo] ?? [], 0, $quedan);
            $quedan -= count($detalle[$grupo]);
        }

        return $detalle;
    }
}
