<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de envío por correo de un DTE (manual). Guarda destinatario, canal
 * (cliente|contabilidad), estado (pendiente|enviado|simulado|error), adjuntos, error y
 * quién/cuándo. El "estado de envío" del DTE se deriva del último registro (sin ninguno =
 * no enviado).
 *
 * `simulado`: el mailer activo NO es real (log/array) → el correo NO salió por SMTP (no se
 * marca como enviado de verdad).
 *
 * `canal` es NULL en los envíos históricos (previos a la columna): se interpretan como
 * envíos al cliente (ver canalEfectivo()); no se hace backfill.
 */
class DteEnvio extends Model
{
    public const CANAL_CLIENTE = 'cliente';

    public const CANAL_CONTABILIDAD = 'contabilidad';

    /** Canales válidos para un envío NUEVO (los históricos pueden traer NULL). */
    public const CANALES = [self::CANAL_CLIENTE, self::CANAL_CONTABILIDAD];

    protected $table = 'dte_envios';

    protected $fillable = [
        'dte_id',
        'destinatario',
        'destinatarios',
        'canal',
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

    /**
     * Canal del envío para mostrar/comparar: los históricos (canal NULL) se leen como
     * envíos al cliente, que es lo único que existía cuando se guardaron.
     */
    public function canalEfectivo(): string
    {
        return filled($this->canal) ? (string) $this->canal : self::CANAL_CLIENTE;
    }

    public function esCanalContabilidad(): bool
    {
        return $this->canalEfectivo() === self::CANAL_CONTABILIDAD;
    }

    /** Lista de destinatarios como texto "a, b, c" (usa destinatarios o el singular). */
    public function destinatariosTexto(): string
    {
        $lista = $this->destinatarios ?: array_filter([$this->destinatario]);

        return implode(', ', $lista);
    }
}
