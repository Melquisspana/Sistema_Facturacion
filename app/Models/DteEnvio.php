<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de envío por correo de un DTE al cliente (manual). Guarda destinatario,
 * estado (pendiente|enviado|simulado|error), adjuntos, error y quién/cuándo. El "estado de
 * envío" del DTE se deriva del último registro (sin ninguno = no enviado).
 *
 * `simulado`: el mailer activo NO es real (log/array) → el correo NO salió por SMTP (no se
 * marca como enviado de verdad).
 */
class DteEnvio extends Model
{
    protected $table = 'dte_envios';

    protected $fillable = [
        'dte_id',
        'destinatario',
        'destinatarios',
        'estado',
        'adjuntos',
        'error',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'destinatarios' => 'array',
        ];
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fueExitoso(): bool
    {
        return $this->estado === 'enviado';
    }

    /** El correo NO salió por SMTP (mailer en modo prueba: log/array). */
    public function esSimulado(): bool
    {
        return $this->estado === 'simulado';
    }

    /** Lista de destinatarios como texto "a, b, c" (usa destinatarios o el singular). */
    public function destinatariosTexto(): string
    {
        $lista = $this->destinatarios ?: array_filter([$this->destinatario]);

        return implode(', ', $lista);
    }
}
