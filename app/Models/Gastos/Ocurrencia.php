<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un período de una regla, ya resuelto: o se generó su obligación, o se omitió con
 * un motivo.
 *
 * Las dos cosas ocupan el período. Esa es la razón de que «omitida» exista como
 * fila y no como ausencia: si omitir fuera simplemente no crear nada, la siguiente
 * corrida del proceso volvería a encontrar el hueco y generaría la deuda que
 * alguien había decidido no crear.
 *
 * `version_regla` guarda con qué versión de la plantilla nació. Es lo que permite
 * explicar una ocurrencia vieja después de que la regla cambió.
 */
class Ocurrencia extends Model
{
    protected $table = 'gastos_ocurrencias';

    public $timestamps = false;

    protected $guarded = ['id'];

    public const ESTADOS = [
        'generada' => 'Generada',
        'omitida' => 'Omitida',
    ];

    protected function casts(): array
    {
        return ['vence' => 'date', 'created_at' => 'datetime'];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(Regla::class, 'regla_id');
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class, 'gasto_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function omitida(): bool
    {
        return $this->estado === 'omitida';
    }

    /** Quién la creó, en palabras. Sin autor es el proceso programado. */
    public function autor(): string
    {
        return $this->registrador?->name ?? 'Proceso programado';
    }
}
