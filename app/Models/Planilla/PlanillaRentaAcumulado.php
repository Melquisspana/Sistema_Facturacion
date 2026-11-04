<?php

namespace App\Models\Planilla;

use App\Models\User;
use App\Services\Planilla\AcumuladoRenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo gravado y la renta retenida de una persona en un año, antes de que sus quincenas
 * estuvieran en el sistema, hasta `hasta` inclusive. La confirma una persona.
 *
 * {@see AcumuladoRenta} explica cómo se usa en el recálculo.
 */
class PlanillaRentaAcumulado extends Model
{
    protected $table = 'planilla_renta_acumulados';

    protected $fillable = [
        'planilla_empleado_id', 'anio', 'hasta', 'gravado', 'renta_retenida', 'observaciones', 'registrado_por',
    ];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'hasta' => 'date', 'gravado' => 'decimal:2', 'renta_retenida' => 'decimal:2'];
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
