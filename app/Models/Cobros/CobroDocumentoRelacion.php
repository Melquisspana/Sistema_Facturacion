<?php

namespace App\Models\Cobros;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relación NC→CCF de un documento EXTERNO, tal como la declara el `documentoRelacionado`
 * del JSON de la nota. Es el equivalente de `dtes.dte_relacionado_id` para lo que no tiene
 * fila en `dtes`. Una NC puede declarar varias; se guardan todas.
 *
 * `ccf_cobro_documento_id` nulo significa que el CCF declarado todavía no está en el
 * seguimiento: la relación existe, lo que falta es su destino. No se resuelve por importe,
 * OC ni fecha.
 */
class CobroDocumentoRelacion extends Model
{
    protected $table = 'cobro_documento_relaciones';

    protected $fillable = [
        'nc_cobro_documento_id',
        'codigo_generacion_relacionado',
        'tipo_documento_relacionado',
        'fecha_emision_relacionado',
        'ccf_cobro_documento_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision_relacionado' => 'date',
        ];
    }

    public function nc(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'nc_cobro_documento_id');
    }

    public function ccf(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'ccf_cobro_documento_id');
    }

    public function resuelta(): bool
    {
        return $this->ccf_cobro_documento_id !== null;
    }
}
