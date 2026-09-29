<?php

namespace App\Models\Planilla;

use App\Models\Gastos\Pago;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un lote de pago: «el día 16 se pagó a estas ocho personas».
 *
 * Es una AGRUPACIÓN OPERATIVA y nada más. No guarda importes propios a propósito: si
 * los guardara, existiría la posibilidad de que el total del lote y la suma de sus
 * pagos discreparan, y entonces habría dos verdades sobre el mismo dinero. El total se
 * calcula siempre desde los pagos.
 *
 * Tampoco cambia la regla de Gastos de un beneficiario por pago: un lote son N pagos
 * normales, cada uno con su beneficiario, sus aplicaciones y su reversión propia.
 */
class PlanillaLote extends Model
{
    protected $table = 'planilla_lotes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'created_at' => 'datetime'];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class, 'planilla_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function pagos(): BelongsToMany
    {
        return $this->belongsToMany(Pago::class, 'planilla_lote_pagos', 'planilla_lote_id', 'pago_id');
    }
}
