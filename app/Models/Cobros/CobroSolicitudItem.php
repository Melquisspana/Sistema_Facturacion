<?php

namespace App\Models\Cobros;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una solicitud: qué documento viajó y con qué datos EXACTOS se escribió.
 *
 * El snapshot de los cinco datos del albarán no es redundancia. Si mañana se corrige el
 * albarán del documento —porque estaba mal vinculado, por ejemplo—, lo que se presentó ese
 * día sigue siendo lo que dice esta fila. El archivo se genera UNA vez desde estos datos y
 * las descargas siguientes entregan la copia archivada; leer los datos del albarán actual
 * haría que el archivo, al generarse, no fuera lo que se decidió presentar.
 */
class CobroSolicitudItem extends Model
{
    use HasFactory;

    protected $table = 'cobro_solicitud_items';

    protected $fillable = [
        'cobro_solicitud_id',
        'cobro_documento_id',
        'orden',
        'sala_codigo',
        'albaran_numero',
        'albaran_anio',
        'albaran_mes',
        'albaran_tipo',
        'monto',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'albaran_anio' => 'integer',
            'albaran_mes' => 'integer',
            'monto' => 'decimal:2',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(CobroSolicitud::class, 'cobro_solicitud_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'cobro_documento_id');
    }
}
