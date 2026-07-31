<?php

namespace App\Models\Planta;

use App\Enums\Planta\TipoUbicacion;
use App\Exceptions\Planta\UbicacionSistemaProtegidaException;
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

    /**
     * Candado de las ubicaciones de SISTEMA.
     *
     * `es_sistema = true` lo declaró la migración del paso 2 como «no se edita ni
     * se elimina», pero hasta ahora era solo un comentario. Con los traslados
     * pasó a tener consecuencias: el servicio exige encontrar EXACTAMENTE una
     * ubicación de tránsito de sistema, activa y sin operación manual, así que
     * desactivarla o cambiarle el tipo rompe los envíos con un error que nadie
     * relacionaría con haber tocado una casilla en un formulario.
     *
     * Solo protege las de sistema: las bodegas normales se editan y se desactivan
     * con toda normalidad.
     *
     * Mismo límite que el resto de candados de Eloquent del módulo: no cubre
     * `query()->update(...)` ni el SQL crudo. Es defensa en profundidad.
     */
    protected static function booted(): void
    {
        static::updating(function (self $ubicacion): void {
            if (! (bool) $ubicacion->getOriginal('es_sistema')) {
                return;
            }

            $codigo = (string) $ubicacion->getOriginal('codigo');

            foreach (['codigo', 'tipo', 'es_sistema', 'permite_operacion_manual'] as $campo) {
                if ($ubicacion->isDirty($campo)) {
                    throw UbicacionSistemaProtegidaException::campoProtegido($codigo, $campo);
                }
            }

            if ($ubicacion->isDirty('activo') && ! $ubicacion->activo) {
                throw UbicacionSistemaProtegidaException::desactivar($codigo);
            }
        });

        static::deleting(function (self $ubicacion): void {
            if ($ubicacion->es_sistema) {
                throw UbicacionSistemaProtegidaException::eliminar((string) $ubicacion->codigo);
            }
        });
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
