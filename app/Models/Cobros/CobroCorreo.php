<?php

namespace App\Models\Cobros;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un correo del cliente ya leído: el acuse de RECIBIDO o el de OBSERVACIONES.
 *
 * El sistema solo LEE el buzón. No manda, no responde, no marca como leído y no mueve
 * nada: lo único que hace es copiar el mensaje y quedarse con lo que pudo interpretar.
 *
 * Se guarda AUNQUE no se pueda asociar a ninguna solicitud. Un correo que no casó es
 * trabajo pendiente de una persona; descartarlo en silencio sería perder justamente el
 * caso que hay que mirar. Por eso `estado = sin_asociar` es un resultado normal y visible,
 * no un fallo.
 *
 * `gmail_message_id` es único: releer el buzón no crea filas nuevas.
 */
class CobroCorreo extends Model
{
    use HasFactory;

    protected $table = 'cobro_correos';

    protected $fillable = [
        'cliente_id',
        'gmail_message_id',
        'gmail_thread_id',
        'tipo',
        'asunto',
        'remitente',
        'fecha_mensaje',
        'cuerpo',
        'archivo_referido',
        'referencia_calleja',
        'fecha_programada_pago',
        'estado',
        'motivo',
        'cobro_solicitud_id',
        'procesado_en',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo' => 'desconocido',
        'estado' => 'pendiente',
    ];

    protected function casts(): array
    {
        return [
            'fecha_mensaje' => 'datetime',
            'fecha_programada_pago' => 'date',
            'procesado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(CobroSolicitud::class, 'cobro_solicitud_id');
    }
}
