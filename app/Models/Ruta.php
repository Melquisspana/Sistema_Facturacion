<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ruta comercial de visita/cobro. Ver la migración para el detalle del alcance.
 *
 * SIN SoftDeletes a propósito: una ruta no se elimina, se DESACTIVA. Desactivarla
 * la saca de los formularios de salida nueva pero conserva las salas asignadas y
 * el historial de salidas ya hechas, que es lo que se querría consultar.
 *
 * Auditoría: alta/edición/activación con spatie/activitylog.
 */
class Ruta extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'rutas';

    protected $fillable = [
        'nombre',
        'activa',
        'frecuencia_objetivo_dias',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'frecuencia_objetivo_dias' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('ruta')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la ruta',
                'updated' => 'actualizó la ruta',
                'deleted' => 'eliminó la ruta',
                default => $evento,
            });
    }

    /** Salas cuya ruta HABITUAL es esta. No son las salas de una salida concreta. */
    public function sucursales(): HasMany
    {
        return $this->hasMany(ClienteSucursal::class, 'ruta_id');
    }

    public function salidas(): HasMany
    {
        return $this->hasMany(SalidaRuta::class, 'ruta_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
