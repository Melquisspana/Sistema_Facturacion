<?php

namespace App\Models\Planta;

use App\Exceptions\Planta\LoteGenericoNoAplicableException;
use App\Services\Planta\LoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Identidad trazable de una entrada física de un insumo.
 *
 * SIN SoftDeletes, a diferencia del resto de catálogos: un lote con movimientos
 * no puede desaparecer del historial ni siquiera lógicamente. Para retirarlo de
 * la operación se usa `activo = false`.
 *
 * No guarda cantidad: eso son las existencias, que se derivan del mayor.
 *
 * LOTES GENÉRICOS. Los crea {@see LoteService} para los
 * insumos que no controlan lotes, con código determinista `GEN-<insumo_id>`.
 * Son lotes de SISTEMA: {@see booted()} impide editarlos y eliminarlos, y
 * {@see scopeReales()} los deja fuera de lo que ve el usuario. Su
 * `fecha_recepcion` es la de la operación que los creó y no se reescribe nunca.
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

    /**
     * Candado del lote genérico. Es de sistema: su código es determinista, su
     * fecha es la de la operación que lo creó y su unicidad por insumo es lo que
     * mantiene coherente el bucket. Editarlo o borrarlo no arregla nada y sí
     * puede dejar sin lote a saldo ya escrito en el mayor.
     *
     * Solo bloquea el genérico: los lotes reales se editan con normalidad.
     *
     * Mismo límite que el resto de candados de Eloquent del módulo: no cubre
     * `PlantaLote::query()->update(...)` ni el SQL crudo, porque esas rutas no
     * pasan por una instancia del modelo. Es defensa en profundidad.
     */
    protected static function booted(): void
    {
        static::updating(function (self $lote): void {
            if ((bool) $lote->getOriginal('es_generico')) {
                throw LoteGenericoNoAplicableException::inmutable($lote->id, 'edición');
            }
        });

        static::deleting(function (self $lote): void {
            if ($lote->es_generico) {
                throw LoteGenericoNoAplicableException::inmutable($lote->id, 'borrado');
            }
        });
    }

    /**
     * Lotes REALES: los que el usuario reconoce como lote. El genérico es un
     * detalle interno del motor de inventario y no debe aparecer en selectores,
     * listados ni reportes de lotes.
     */
    public function scopeReales(Builder $query): Builder
    {
        return $query->where('es_generico', false);
    }

    /** Solo el lote de sistema de los insumos sin control de lotes. */
    public function scopeGenericos(Builder $query): Builder
    {
        return $query->where('es_generico', true);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(PlantaInsumo::class, 'planta_insumo_id');
    }

    /** Movimientos del mayor que afectan a este lote. FK restrictOnDelete. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(PlantaMovimiento::class, 'planta_lote_id');
    }

    /** Saldos por bucket de este lote. Proyección del mayor, solo lectura. */
    public function existencias(): HasMany
    {
        return $this->hasMany(PlantaExistencia::class, 'planta_lote_id');
    }

    /** Proveedor que lo entregó. Nullable: puede no conocerse o haberse borrado. */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(PlantaProveedor::class, 'planta_proveedor_id');
    }
}
