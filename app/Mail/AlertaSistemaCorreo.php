<?php

namespace App\Mail;

use App\Services\Alertas\Avisador;
use App\Services\Alertas\Hallazgo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso interno de que algo falló, sigue fallando o ya se normalizó. Lo arma
 * {@see Avisador}; el contenido sale de un {@see Hallazgo},
 * que ya viene sin datos sensibles.
 */
class AlertaSistemaCorreo extends Mailable
{
    use Queueable;

    public function __construct(
        public string $accion,
        public string $nombre,
        public ?Hallazgo $hallazgo,
        public string $desde,
        public string $enlace,
        public bool $ultimoDelDia = false,
    ) {}

    public function envelope(): Envelope
    {
        $titulo = $this->hallazgo?->titulo ?? $this->nombre;

        return new Envelope(subject: match ($this->accion) {
            'normalizado' => 'Ya se normalizó: '.$this->nombre,
            'sigue' => 'Sigue la falla: '.$titulo,
            default => 'Alerta: '.$titulo,
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.alerta-sistema', with: [
            'accion' => $this->accion,
            'nombre' => $this->nombre,
            'hallazgo' => $this->hallazgo,
            'desde' => $this->desde,
            'enlace' => $this->enlace,
            'ultimoDelDia' => $this->ultimoDelDia,
        ]);
    }
}
