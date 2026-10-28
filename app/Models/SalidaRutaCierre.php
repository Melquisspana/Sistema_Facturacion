<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalidaRutaCierre extends Model
{
    protected $table = 'salida_ruta_cierres';

    protected $fillable = [
        'salida_ruta_id', 'fecha', 'entrega_dinero_personal_id',
        'efectivo_esperado', 'cheques_esperado', 'transferencias_esperado',
        'efectivo_cobrado', 'cheques_cobrado', 'transferencias_cobrado',
        'gastos_total', 'a_entregar', 'cerrado_por', 'cerrado_en',
        'reabierto_por', 'reabierto_en', 'reabierto_motivo',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date', 'cerrado_en' => 'datetime', 'reabierto_en' => 'datetime',
            'efectivo_esperado' => 'decimal:2', 'cheques_esperado' => 'decimal:2', 'transferencias_esperado' => 'decimal:2',
            'efectivo_cobrado' => 'decimal:2', 'cheques_cobrado' => 'decimal:2', 'transferencias_cobrado' => 'decimal:2',
            'gastos_total' => 'decimal:2', 'a_entregar' => 'decimal:2',
        ];
    }

    /** Cerrado si tiene cierre y ninguna reapertura igual o posterior a ese cierre. */
    public function estaCerrado(): bool
    {
        return $this->cerrado_en !== null && ($this->reabierto_en === null || $this->reabierto_en->lt($this->cerrado_en));
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function entregaDinero(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'entrega_dinero_personal_id');
    }
}
