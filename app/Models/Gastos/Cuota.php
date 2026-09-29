<?php

namespace App\Models\Gastos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un vencimiento del gasto. Sin calendario pactado hay UNA cuota (con fecha o
 * sin ella); no se duplica el gasto por cada cuota.
 */
class Cuota extends Model
{
    protected $table = 'gastos_cuotas';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'vence' => 'date'];
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class);
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(Ajuste::class, 'cuota_id');
    }

    public function venceAntesDe(string $hoy): bool
    {
        return $this->vence !== null && $this->vence->format('Y-m-d') < $hoy;
    }
}
