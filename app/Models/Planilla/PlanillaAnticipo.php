<?php

namespace App\Models\Planilla;

use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Dinero que ya se le entregó a alguien y que se va a recuperar por planilla.
 *
 * Existe como FILA y no como texto porque una referencia escrita no impide nada: en la
 * quincena siguiente alguien vuelve a escribir «recibo 148» y el mismo anticipo se
 * descuenta dos veces. Con la fila, lo que queda por recuperar es una resta:
 *
 *      pendiente = importe − aplicaciones vigentes
 *
 * `pago_id` enlaza con el pago REAL de Gastos cuando existe, y es único: ese pago no
 * puede quedar registrado como dos anticipos. Cuando el anticipo se entregó fuera del
 * sistema, `pago_id` es NULL y `referencia` lo describe —pero el control sigue siendo
 * el importe y sus aplicaciones, no el texto—.
 */
class PlanillaAnticipo extends Model
{
    protected $table = 'planilla_anticipos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'importe' => 'decimal:2'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(PlanillaEmpleado::class, 'planilla_empleado_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(PlanillaConcepto::class, 'planilla_anticipo_id');
    }

    /** Lo ya recuperado, en centavos. */
    public function recuperado(): int
    {
        return DB::table('planilla_anticipo_aplicaciones')
            ->where('planilla_anticipo_id', $this->id)
            ->pluck('importe')
            ->sum(fn ($v) => Dinero::centavos((string) $v));
    }

    /** Lo que todavía queda por recuperar, en centavos. Nunca negativo. */
    public function pendiente(): int
    {
        return max(Dinero::centavos((string) $this->importe) - $this->recuperado(), 0);
    }

    /** ¿De dónde salió el dinero? Solo para mostrarlo. */
    public function origen(): string
    {
        if ($this->pago_id !== null) {
            return 'Pago #'.$this->pago_id.' del '.$this->pago?->fecha?->format('d/m/Y');
        }

        return filled($this->referencia) ? 'Fuera del sistema · '.$this->referencia : 'Fuera del sistema';
    }
}
