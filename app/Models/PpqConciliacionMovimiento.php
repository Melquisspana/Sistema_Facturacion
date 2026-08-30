<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio concreto en el estado de cobro de un renglón, dentro de una corrida.
 *
 * Guarda el valor ANTERIOR y el NUEVO de las tres cosas que definen un pago —estado,
 * fecha e importe—, y solo se escribe cuando algo cambió de verdad: un renglón que la
 * corrida dejó igual no genera movimiento. Así la bitácora dice qué pasó y no qué se miró.
 *
 * Ese par anterior/nuevo es lo que permite dos cosas que antes no existían: reconstruir
 * cómo llegó un documento a su estado actual, y demostrar que una segunda corrida no
 * borró el pago que había registrado la primera.
 *
 * Solo se inserta.
 */
class PpqConciliacionMovimiento extends Model
{
    use HasFactory;

    /** Bitácora: solo `created_at`. */
    public const UPDATED_AT = null;

    protected $table = 'ppq_conciliacion_movimientos';

    protected $fillable = [
        'ppq_conciliacion_id',
        'ppq_item_id',
        'estado_anterior',
        'estado_nuevo',
        'fecha_pago_anterior',
        'fecha_pago_nueva',
        'monto_pagado_anterior',
        'monto_pagado_nuevo',
        'linea_txt',
    ];

    protected function casts(): array
    {
        return [
            'fecha_pago_anterior' => 'date',
            'fecha_pago_nueva' => 'date',
            'monto_pagado_anterior' => 'decimal:2',
            'monto_pagado_nuevo' => 'decimal:2',
            'linea_txt' => 'integer',
        ];
    }

    public function conciliacion(): BelongsTo
    {
        return $this->belongsTo(PpqConciliacion::class, 'ppq_conciliacion_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PpqItem::class, 'ppq_item_id');
    }

    /** ¿Este movimiento QUITÓ un cobro que ya estaba registrado? */
    public function deshizoUnPago(): bool
    {
        return $this->estado_anterior !== null && $this->estado_nuevo === null;
    }

    /** Frase para el historial: «pendiente → pagado» o «pagado → pendiente». */
    public function transicion(): string
    {
        $nombre = fn (?string $estado) => match ($estado) {
            'pagado' => 'pagado',
            'aplicada' => 'aplicada',
            default => 'pendiente',
        };

        return $nombre($this->estado_anterior).' → '.$nombre($this->estado_nuevo);
    }
}
