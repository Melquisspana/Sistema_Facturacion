<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PuntoVenta extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'puntos_venta';

    protected $fillable = [
        'establecimiento_id',
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Auditoría del marco fiscal: igual que el establecimiento, el `codigo` del punto
     * de venta va dentro del número de control de cada DTE. Sin secretos.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('configuracion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el punto de venta',
                'updated' => 'modificó el punto de venta',
                'deleted' => 'eliminó el punto de venta',
                'restored' => 'restauró el punto de venta',
                default => $evento,
            });
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    public function correlativos(): HasMany
    {
        return $this->hasMany(Correlativo::class);
    }
}
