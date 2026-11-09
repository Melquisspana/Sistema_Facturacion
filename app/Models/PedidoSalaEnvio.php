<?php

namespace App\Models;

use App\Services\Rutas\FacturarPedido;
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

    protected $fillable = ['numero', 'clave', 'fecha', 'registrado_por', 'anulado_en', 'anulado_por', 'dte_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'anulado_en' => 'datetime',
            'numero' => 'integer',
            'registrado_por' => 'integer',
            'anulado_por' => 'integer',
            'dte_id' => 'integer',
        ];
    }

    /**
     * El CCF donde se facturó (lo pone {@see FacturarPedido}). Con los borrados: el enlace
     * se ve aunque el borrador ya no exista. Si sigue facturado lo dice {@see estaFacturado()}.
     */
    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class)->withTrashed();
    }

    /** Vigentes y sin un CCF que cuente (nunca facturado, o su CCF se borró, invalidó o rechazó). */
    public function scopePorFacturar(Builder $q): Builder
    {
        return $q->vigentes()->where(fn (Builder $w) => $w->whereNull('dte_id')
            ->orWhereDoesntHave('dte', fn (Builder $d) => $d->cuentaComoFacturado()));
    }

    /** ¿Ya está en un CCF que cuenta? Usa la relación si vino precargada. */
    public function estaFacturado(): bool
    {
        return $this->dte_id !== null && (bool) $this->dte?->sigueFacturado();
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
