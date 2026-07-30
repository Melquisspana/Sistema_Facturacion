<?php

namespace App\Models\Planta;

use App\Enums\Planta\TipoUbicacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dónde se guarda el inventario. Catálogo plano en Fase 2.
 *
 * Da acceso a sus movimientos y a sus existencias, pero NO conoce saldos
 * propios: las existencias son una proyección del mayor y son de solo lectura.
 */
class PlantaUbicacion extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'planta_ubicaciones';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'es_sistema',
        'permite_operacion_manual',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoUbicacion::class,
            'es_sistema' => 'boolean',
            'permite_operacion_manual' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_ubicacion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la ubicación de planta',
                'updated' => 'actualizó la ubicación de planta',
                'deleted' => 'eliminó la ubicación de planta',
                default => $evento,
            });
    }

    /** Movimientos del mayor ocurridos en esta ubicación. FK restrictOnDelete. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'planta_ubicacion_id');
    }

    /**
     * Saldos por bucket en esta ubicación. Proyección del mayor, solo lectura.
     * En la ubicación de TRÁNSITO cada fila lleva además su `planta_traslado_id`.
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(PlantaExistencia::class, 'planta_ubicacion_id');
    }
}
