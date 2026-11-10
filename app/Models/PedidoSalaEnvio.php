<?php

namespace App\Models;

use App\Services\Rutas\PedidosFabrica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una salida de la fábrica para un pedido de sala: n.º 1, 2, 3… por pedido, con la fecha
 * que pone el servidor. No se borra: se anula y conserva su número. La regla vive en
 * {@see PedidosFabrica}.
 */
class PedidoSalaEnvio extends Model
{
    protected $table = 'pedido_sala_envios';

    protected $fillable = ['numero', 'clave', 'fecha', 'registrado_por', 'anulado_en', 'anulado_por'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'anulado_en' => 'datetime',
            'numero' => 'integer',
            'registrado_por' => 'integer',
            'anulado_por' => 'integer',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoSala::class, 'pedido_sala_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoSalaEnvioLinea::class, 'pedido_sala_envio_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('anulado_en');
    }

    public function estaVigente(): bool
    {
        return $this->anulado_en === null;
    }
}
