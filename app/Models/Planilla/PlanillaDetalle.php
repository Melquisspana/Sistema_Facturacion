<?php

namespace App\Models\Planilla;

use App\Models\Gastos\Gasto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La línea de UNA persona en UNA planilla.
 *
 * Guarda una fotografía del nombre y el cargo: un recibo de marzo no puede cambiar
 * porque en junio a alguien lo asciendan.
 *
 * `gasto_id` es la obligación con la persona por lo que se le paga, y lleva índice
 * único: es lo que impide que confirmar dos veces genere dos deudas por el mismo
 * sueldo.
 */
class PlanillaDetalle extends Model
{
    protected $table = 'planilla_detalles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'salario' => 'decimal:2', 'total_ingresos' => 'decimal:2',
            'descuentos' => 'decimal:2', 'a_pagar' => 'decimal:2',
            // Fechas de verdad: sin esto vuelven como cadena y la vista no puede
            // formatearlas.
            'periodo_desde' => 'date', 'periodo_hasta' => 'date',
        ];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(PlanillaEmpleado::class, 'planilla_empleado_id');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(PlanillaConcepto::class, 'planilla_detalle_id')->orderBy('orden')->orderBy('id');
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class, 'gasto_id');
    }
}
