<?php

namespace App\Models\Planta;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Qué se fabrica: la identidad del dulce, independiente del empaque.
 *
 * Sin relación alguna con el catálogo comercial de Facturación: la columna
 * `producto_id` no existe a propósito.
 */
class PlantaProductoBase extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** La tabla es `planta_productos_base`; Eloquent inferiría otra cosa. */
    protected $table = 'planta_productos_base';

    protected $fillable = [
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_producto_base')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el producto base de planta',
                'updated' => 'actualizó el producto base de planta',
                'deleted' => 'eliminó el producto base de planta',
                default => $evento,
            });
    }

    /** Formatos comerciales de este producto. */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(PlantaPresentacion::class, 'planta_producto_base_id');
    }

    /** Configuraciones de empaque de todas sus presentaciones. */
    public function empaqueConfigs(): HasManyThrough
    {
        return $this->hasManyThrough(
            PlantaEmpaqueConfig::class,
            PlantaPresentacion::class,
            'planta_producto_base_id',
            'planta_presentacion_id'
        );
    }
}
