<?php

namespace App\Models\Planta;

use App\Enums\Planta\TipoUbicacion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dónde se guarda el inventario. Catálogo plano en Fase 2.
 *
 * Sin relaciones operativas todavía: existencias y movimientos llegan en el
 * paso 5, y este modelo no debe conocer saldos.
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
}
