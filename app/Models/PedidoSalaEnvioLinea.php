<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un producto de un envío de la fábrica: la línea del pedido y cuánto llevó (siempre > 0). */
class PedidoSalaEnvioLinea extends Model
{
    public $timestamps = false;

    protected $table = 'pedido_sala_envio_lineas';

    protected $fillable = ['pedido_sala_linea_id', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'pedido_sala_linea_id' => 'integer'];
    }

    public function envio(): BelongsTo
    {
        return $this->belongsTo(PedidoSalaEnvio::class, 'pedido_sala_envio_id');
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(PedidoSalaLinea::class, 'pedido_sala_linea_id');
    }

    /** La cantidad sin ceros de más: «12», «2.5». */
    public function cantidadLegible(): string
    {
        return PedidoSalaLinea::legible((string) $this->cantidad);
    }
}
