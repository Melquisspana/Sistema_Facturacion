<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Una obligación: qué debemos, a quién y para cuándo.
 *
 * `importe` puede ser NULL —«todavía no conozco el monto»—. Eso NO es cero: un
 * gasto sin importe no suma a pendiente, no suma a vencido y no se puede pagar
 * hasta completarlo.
 */
class Gasto extends Model
{
    protected $guarded = ['id'];

    public const AMBITOS = ['empresarial' => 'De la empresa', 'personal' => 'Personal'];

    public const NATURALEZAS = [
        'operativo' => 'Gasto habitual o extraordinario',
        'compra' => 'Compra de materiales, productos o equipo',
        'tributo' => 'Pago de impuestos',
        'retiro' => 'Retiro de propietario',
    ];

    /*
     * La columna `certeza` (qué tan firme es el importe: provisional, confirmado_usuario,
     * confirmado; NULL = sin marca) sigue en la tabla y el modelo la acepta, pero ninguna
     * pantalla la escribe. Por eso desde el 11/11/2026 tampoco se muestra ni suma en
     * ningún total: afirmaba algo que nadie cargó. Nunca cambió un cálculo.
     */

    public const DOCUMENTACION = [
        'pendiente' => 'Falta adjuntar',
        'adjunto' => 'Adjunto',
        'no_entregaron' => 'No entregaron documento',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'periodo_desde' => 'date', 'periodo_hasta' => 'date', 'anulado_at' => 'datetime'];
    }

    /**
     * Anulado no es borrado: sigue en su ficha y en el historial, pero desde
     * `anulado_at` ya no es deuda. Ver App\Services\Gastos\AnularGasto.
     */
    public function anulado(): bool
    {
        return $this->anulado_at !== null;
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }

    public function ajustes(): HasManyThrough
    {
        return $this->hasManyThrough(Ajuste::class, Cuota::class, 'gasto_id', 'cuota_id');
    }

    public function fuentes(): HasMany
    {
        return $this->hasMany(Fuente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function esPersonal(): bool
    {
        return $this->ambito === 'personal';
    }

    public function montoDesconocido(): bool
    {
        return $this->importe === null;
    }

    public function etiquetaAmbito(): string
    {
        return self::AMBITOS[$this->ambito] ?? $this->ambito;
    }
}
