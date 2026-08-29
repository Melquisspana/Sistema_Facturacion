<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una nota de crédito dentro de un lote de exportación. `dte_id` es único global. */
class NcExportacionItem extends Model
{
    use HasFactory;

    protected $table = 'nc_exportacion_items';

    protected $fillable = ['nc_exportacion_id', 'dte_id', 'orden'];

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    public function exportacion(): BelongsTo
    {
        return $this->belongsTo(NcExportacion::class, 'nc_exportacion_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }
}
