<?php

namespace App\Models\Gastos;

use App\Models\DocumentoRecibido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De dónde salió la obligación, o qué la respalda.
 *
 *  - `deuda`: el documento ORIGINÓ el gasto. Uno solo por documento; ese es el
 *    candado contra registrar dos veces la misma factura.
 *  - `respaldo`: el documento llegó DESPUÉS de un gasto que ya existía. No crea
 *    deuda nueva; solo deja el papel colgado donde corresponde.
 */
class Fuente extends Model
{
    protected $table = 'gastos_fuentes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRecibido::class, 'documento_recibido_id');
    }
}
