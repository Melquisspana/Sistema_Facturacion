<?php

namespace App\Models\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Qué se almacena. Define cómo se comporta el insumo en el inventario, pero
 * NO conoce saldos: las existencias son otra tabla y se derivan del mayor.
 */
class PlantaInsumo extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_insumos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'unidad_base',
        'controla_lotes',
        'permite_fraccion',
        'factor_conversion_sugerido',
        'unidad_recepcion_sugerida',
        'contenido_sugerido',
        'stock_minimo',
        'activo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoInsumo::class,
            'unidad_base' => UnidadBase::class,
            'controla_lotes' => 'boolean',
            'permite_fraccion' => 'boolean',
            'factor_conversion_sugerido' => 'decimal:8',
            'contenido_sugerido' => 'decimal:4',
            'stock_minimo' => 'decimal:4',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_insumo')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el insumo de planta',
                'updated' => 'actualizó el insumo de planta',
                'deleted' => 'eliminó el insumo de planta',
                default => $evento,
            });
    }

    /** Lotes de este insumo. La FK es restrictOnDelete: no se borra con historial. */
    public function lotes(): HasMany
    {
        return $this->hasMany(PlantaLote::class, 'planta_insumo_id');
    }

    /** Configuraciones de empaque donde este insumo actúa como bolsa. */
    public function configsComoBolsa(): HasMany
    {
        return $this->hasMany(PlantaEmpaqueConfig::class, 'planta_insumo_bolsa_id');
    }

    /** Configuraciones de empaque donde este insumo actúa como viñeta. */
    public function configsComoVinieta(): HasMany
    {
        return $this->hasMany(PlantaEmpaqueConfig::class, 'planta_insumo_vinieta_id');
    }
}
