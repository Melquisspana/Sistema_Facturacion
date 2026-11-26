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
 *
 * `adjuntos` dice además qué documento viajó: «PDF» es el completo (con sello);
 * «PDF provisional» (emitido en contingencia) y «PDF preliminar» (sin sello) no lo son.
 * Lo anota el job con el mismo documento con que arma el PDF ({@see etiquetaPdf()}).
 */
class DteEnvio extends Model
{
    public const CANAL_CLIENTE = 'cliente';

    public const CANAL_CONTABILIDAD = 'contabilidad';

    /** Canales válidos para un envío NUEVO (los históricos pueden traer NULL). */
    public const CANALES = [self::CANAL_CLIENTE, self::CANAL_CONTABILIDAD];

    /** El PDF que viajó era el de un documento emitido en contingencia, sin sello todavía. */
    public const PDF_PROVISIONAL = 'PDF provisional';

    /** El PDF que viajó era preliminar: sin sello y fuera de contingencia (borrador, en duda, rechazado). */
    public const PDF_PRELIMINAR = 'PDF preliminar';

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

    /** @return BelongsTo<Dte, $this> */
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
     * Cómo se anota en `adjuntos` el PDF de este documento tal como está ahora: completo
     * con el sello (igual que el PDF, que deja de ser preliminar al tenerlo), provisional
     * si se emitió en contingencia, y preliminar en cualquier otro caso.
     */
    public static function etiquetaPdf(Dte $dte): string
    {
        if (filled($dte->sello_recepcion)) {
            return 'PDF';
        }

        return $dte->esTransitorio() ? self::PDF_PROVISIONAL : self::PDF_PRELIMINAR;
    }

    /**
     * ¿Le llegó al destinatario el documento completo? Solo un envío que salió y no fue
     * provisional ni preliminar. Los anteriores a la marca cuentan como completos, como
     * hasta ahora.
     */
    public function llevoDocumentoCompleto(): bool
    {
        $adjuntos = (string) $this->adjuntos;

        return $this->estado === 'enviado'
            && ! str_starts_with($adjuntos, self::PDF_PROVISIONAL)
            && ! str_starts_with($adjuntos, self::PDF_PRELIMINAR);
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
