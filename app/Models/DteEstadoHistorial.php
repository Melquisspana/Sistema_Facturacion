<?php

namespace App\Models;

use App\Enums\EstadoDte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DteEstadoHistorial extends Model
{
    protected $table = 'dte_estado_historial';

    /** La tabla solo tiene created_at (es bitácora, no se actualiza). */
    public const UPDATED_AT = null;

    protected $fillable = [
        'dte_id', 'estado_anterior', 'estado_nuevo', 'user_id', 'comentario',
    ];

    protected function casts(): array
    {
        return [
            'estado_anterior' => EstadoDte::class,
            'estado_nuevo' => EstadoDte::class,
        ];
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
