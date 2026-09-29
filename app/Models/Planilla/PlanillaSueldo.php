<?php

namespace App\Models\Planilla;

use App\Models\User;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de sueldo habitual: cuánto, y desde cuándo.
 *
 * Las filas no se editan ni se borran para «corregir el sueldo»: se agrega otra con la
 * fecha nueva. Lo único que se reescribe es una fila del MISMO día, porque dos importes
 * vigentes el mismo día serían una pregunta sin respuesta.
 *
 * {@see SueldoHabitual} explica por qué es una línea de tiempo.
 */
class PlanillaSueldo extends Model
{
    protected $table = 'planilla_sueldos';

    protected $fillable = [
        'planilla_empleado_id', 'importe', 'vigente_desde', 'motivo', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'vigente_desde' => 'date'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(PlanillaEmpleado::class, 'planilla_empleado_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
