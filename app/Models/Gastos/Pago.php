<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una salida de dinero DECLARADA por el operador. «Registrado» no significa
 * liquidado por el banco: este módulo no ejecuta transferencias ni concilia.
 *
 * No tiene ámbito propio: los subtotales empresarial/personal se derivan de sus
 * aplicaciones a cuotas, así que no hay un segundo reparto que las contradiga.
 */
class Pago extends Model
{
    protected $table = 'gastos_pagos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'fecha' => 'date', 'revertido_at' => 'datetime'];
    }

    public function pagador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function reversor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revertido_por');
    }

    public function vigente(): bool
    {
        return $this->revertido_at === null;
    }

    public function etiquetaMetodo(): string
    {
        return config('gastos.metodos')[$this->metodo] ?? $this->metodo;
    }
}
