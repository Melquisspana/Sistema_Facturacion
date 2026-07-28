<?php

namespace App\Models\Planta;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cómo se presenta un producto base: «Coco 85 g».
 *
 * No es un producto fiscal y no tiene precio. La bolsa y la viñeta que le
 * corresponden viven en `planta_empaque_configs`, que llega en el paso 4.
 */
class PlantaPresentacion extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_presentaciones';

    protected $fillable = [
        'planta_producto_base_id',
        'codigo',
        'nombre',
        'contenido',
        'unidad_contenido',
        'unidades_por_bulto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'contenido' => 'decimal:4',
            'unidades_por_bulto' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_presentacion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la presentación de planta',
                'updated' => 'actualizó la presentación de planta',
                'deleted' => 'eliminó la presentación de planta',
                default => $evento,
            });
    }

    public function productoBase(): BelongsTo
    {
        return $this->belongsTo(PlantaProductoBase::class, 'planta_producto_base_id');
    }
}
