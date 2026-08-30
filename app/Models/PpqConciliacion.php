<?php

namespace App\Models;

use App\Enums\OrigenConciliacionPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una CORRIDA de conciliación sobre un lote: quién, cuándo, con qué archivo y con qué
 * resultado. Ver la migración para el porqué de cada columna.
 *
 * Es una bitácora: se inserta y no se toca. Por eso `UPDATED_AT = null` y por eso no hay
 * ni un método que escriba sobre una fila ya creada.
 */
class PpqConciliacion extends Model
{
    use HasFactory;

    /** Bitácora: solo `created_at`. Una fila que se puede actualizar no prueba nada. */
    public const UPDATED_AT = null;

    protected $table = 'ppq_conciliaciones';

    protected $fillable = [
        'ppq_lote_id',
        'user_id',
        'origen',
        'archivo_nombre',
        'archivo_hash',
        'archivo_path',
        'total_filas',
        'filas_cf',
        'filas_nc',
        'filas_qd',
        'items_cambiados',
        'items_sin_cambio',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'origen' => OrigenConciliacionPpq::class,
            'total_filas' => 'integer',
            'filas_cf' => 'integer',
            'filas_nc' => 'integer',
            'filas_qd' => 'integer',
            'items_cambiados' => 'integer',
            'items_sin_cambio' => 'integer',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(PpqLote::class, 'ppq_lote_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(PpqConciliacionMovimiento::class, 'ppq_conciliacion_id');
    }

    /** ¿Vino de un archivo del cliente, o de una corrección hecha en casa? */
    public function esReversion(): bool
    {
        return $this->origen === OrigenConciliacionPpq::Reversion;
    }

    /**
     * Descripción corta para el historial del lote: «Archivo de pagos · pagos-julio.txt ·
     * 12 renglones actualizados».
     */
    public function resumen(): string
    {
        $que = $this->esReversion()
            ? 'Corrección manual'
            : 'Archivo de pagos'.($this->archivo_nombre ? ' · '.$this->archivo_nombre : '');

        $cambios = match ($this->items_cambiados) {
            0 => 'sin cambios',
            1 => '1 renglón actualizado',
            default => $this->items_cambiados.' renglones actualizados',
        };

        return $que.' · '.$cambios;
    }

    /** Corridas de un lote, de la más reciente a la más vieja. */
    public function scopeDelLote(Builder $q, int $loteId): Builder
    {
        return $q->where('ppq_lote_id', $loteId)->orderByDesc('id');
    }

    /**
     * ¿Este archivo ya se procesó en este lote? Es la comprobación amable que acompaña al
     * índice único: permite responder «ya lo subiste el 12 de agosto» en vez de dejar que
     * la base tire una violación de integridad.
     */
    public static function yaProcesado(int $loteId, string $hash): ?self
    {
        return static::query()
            ->where('ppq_lote_id', $loteId)
            ->where('archivo_hash', $hash)
            ->first();
    }
}
