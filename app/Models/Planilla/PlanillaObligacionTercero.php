<?php

namespace App\Models\Planilla;

use App\Models\Gastos\Gasto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que la planilla le debe a un tercero: la suma de los descuentos de toda la
 * planilla que se le entregan a esa misma persona o institución.
 *
 * Se agrupa POR TERCERO y no por descuento porque a la cooperativa se le hace UN pago
 * por la cuota de todos, no uno por empleado. Y lleva índice único por planilla y
 * tercero: confirmar dos veces no puede crear dos deudas con el mismo.
 *
 * Solo existe cuando la planilla se CONFIRMA. En borrador no hay ninguna.
 */
class PlanillaObligacionTercero extends Model
{
    protected $table = 'planilla_obligaciones_terceros';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class, 'gasto_id');
    }
}
