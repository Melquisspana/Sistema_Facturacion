<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de envío de un DOCUMENTO RECIBIDO (compra) a contabilidad por correo.
 * Guarda destinatario, estado, los adjuntos que realmente viajaron (y los omitidos
 * por tamaño), el error si falló, y quién/cuándo.
 *
 * `simulado`: el mailer activo NO es real (log/array) → el correo no salió por SMTP,
 * así que NO cuenta como enviado (el documento sigue pendiente).
 */
class DocumentoRecibidoEnvio extends Model
{
    protected $table = 'documento_recibido_envios';

    protected $fillable = [
        'documento_recibido_id',
        'destinatario',
        'destinatarios',
        'estado',
        'adjuntos',
        'adjuntos_omitidos',
        'error',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'destinatarios' => 'array',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRecibido::class, 'documento_recibido_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Único estado que cuenta como enviado de verdad (el correo salió por SMTP). */
    public function fueExitoso(): bool
    {
        return $this->estado === 'enviado';
    }

    /** El correo NO salió por SMTP (mailer en modo prueba: log/array). */
    public function esSimulado(): bool
    {
        return $this->estado === 'simulado';
    }

    /** @return array<int, string> */
    public function listaDestinatarios(): array
    {
        return $this->destinatarios ?: array_values(array_filter([$this->destinatario]));
    }
}
