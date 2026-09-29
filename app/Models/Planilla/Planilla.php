<?php

namespace App\Models\Planilla;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La planilla de un período.
 *
 * En BORRADOR se edita libremente y no debe nada: no existe ninguna obligación, no
 * hay nada que pagar y cambiar un importe no tiene consecuencias. CONFIRMADA genera
 * sus obligaciones UNA sola vez y deja de editarse.
 *
 * No calcula nada legal. Los importes los escribe una persona.
 */
class Planilla extends Model
{
    protected $table = 'planillas';

    protected $guarded = ['id'];

    public const TIPOS_PERIODO = [
        'semanal' => 'Semanal',
        'quincenal' => 'Quincenal (dos por mes)',
        'mensual' => 'Mensual',
    ];

    public const CLASES = [
        'regular' => 'Normal',
        'extraordinaria' => 'Fuera de calendario',
    ];

    public const ESTADOS = [
        'borrador' => 'Borrador',
        'confirmada' => 'Confirmada',
        'anulada' => 'Anulada',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date', 'hasta' => 'date', 'fecha_pago' => 'date',
            'confirmada_at' => 'datetime', 'anulada_at' => 'datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PlanillaDetalle::class, 'planilla_id')->orderBy('nombre_snapshot');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function borrador(): bool
    {
        return $this->estado === 'borrador';
    }

    public function confirmada(): bool
    {
        return $this->estado === 'confirmada';
    }

    /** Cómo se lee el período, en palabras. */
    public function periodoEnPalabras(): string
    {
        return $this->desde?->format('d/m/Y').' al '.$this->hasta?->format('d/m/Y');
    }
}
