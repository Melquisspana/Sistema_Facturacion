<?php

namespace App\Models\Cobros;

use App\Models\Concerns\RecortaTextosAColumna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De qué correo y de qué adjunto salió un documento externo del seguimiento. Guarda la
 * huella del adjunto, no su contenido: basta para comprobar que es el mismo archivo sin
 * conservar JSON ni datos del receptor. Un reenvío es otra fila del mismo documento.
 */
class CobroDocumentoProcedencia extends Model
{
    use RecortaTextosAColumna;

    /** @return array<string, int> */
    public static function largosDeTexto(): array
    {
        return [
            'adjunto_nombre' => 160,
        ];
    }

    public const FUENTE_GMAIL_ENVIADOS = 'gmail_enviados';

    protected $table = 'cobro_documento_procedencias';

    protected $fillable = [
        'cobro_documento_id',
        'fuente',
        'gmail_message_id',
        'adjunto_nombre',
        'adjunto_hash',
        'codigo_generacion',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'cobro_documento_id');
    }
}
