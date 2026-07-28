<?php

namespace App\Models\Planta;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Identidad trazable de una entrada física de un insumo.
 *
 * SIN SoftDeletes, a diferencia del resto de catálogos: un lote con movimientos
 * no puede desaparecer del historial ni siquiera lógicamente. Para retirarlo de
 * la operación se usa `activo = false`.
 *
 * No guarda cantidad: eso son las existencias (paso 5). Tampoco resuelve ni
 * crea lotes genéricos `GEN-<insumo_id>`; eso será LoteService, con las
 * recepciones.
 */
class PlantaLote extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'planta_lotes';

    protected $fillable = [
        'planta_insumo_id',
        'planta_proveedor_id',
        'codigo_interno',
        'codigo_proveedor',
        'es_generico',
        'fecha_recepcion',
        'fecha_elaboracion',
        'fecha_vencimiento',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'es_generico' => 'boolean',
            'fecha_recepcion' => 'date',
            'fecha_elaboracion' => 'date',
            'fecha_vencimiento' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('planta_lote')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el lote de planta',
                'updated' => 'actualizó el lote de planta',
                'deleted' => 'eliminó el lote de planta',
                default => $evento,
            });
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_id');
    }

    /** Proveedor que lo entregó. Nullable: puede no conocerse o haberse borrado. */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(PlantaProveedor::class, 'planta_proveedor_id');
    }
}
