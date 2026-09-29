<?php

namespace App\Models\Cobros;

use App\Models\Dte;
use App\Models\NcExportacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué nota de crédito —y en qué lote de NC ya cargado al portal— respaldó a un CCF de una
 * solicitud de quedan. Se congela al crear la solicitud y no se reescribe: si después la
 * nota cambia, esta fila sigue diciendo con qué se presentó ese día.
 */
class CobroSolicitudNota extends Model
{
    protected $table = 'cobro_solicitud_notas';

    protected $fillable = [
        'cobro_solicitud_id',
        'cobro_documento_id',
        'dte_id',
        'nc_exportacion_id',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(CobroSolicitud::class, 'cobro_solicitud_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CobroDocumento::class, 'cobro_documento_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function exportacion(): BelongsTo
    {
        return $this->belongsTo(NcExportacion::class, 'nc_exportacion_id');
    }
}
