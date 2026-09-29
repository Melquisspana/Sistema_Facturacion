<?php

namespace App\Models\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una persona en planilla.
 *
 * Tabla propia con TRES punteros opcionales de identidad —Asistencia, Personal de
 * Rutas y usuario del sistema— para no volver a escribir a nadie y para que se sepa
 * que el Rene de acá es el de allá. Ninguno es una dependencia: la planilla funciona
 * con los tres en NULL, nunca lee marcaciones ni huellas, y **no exige biometría**.
 *
 * Ver la migración para el porqué de no colgarse de `asistencia_empleados`.
 */
class PlanillaEmpleado extends Model
{
    protected $table = 'planilla_empleados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'salario_referencia' => 'decimal:2'];
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function personalRuta(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'personal_ruta_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PlanillaDetalle::class, 'planilla_empleado_id');
    }

    /** ¿De dónde salió esta identidad? Solo para mostrarlo; no cambia ningún permiso. */
    public function origenIdentidad(): string
    {
        return match (true) {
            $this->asistencia_empleado_id !== null => 'Asistencia',
            $this->personal_ruta_id !== null => 'Personal de Rutas',
            $this->user_id !== null => 'Usuario del sistema',
            default => 'Solo planilla',
        };
    }
}
