<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto de un pedido de sala. `cantidad_enviada` y `motivo_no_enviado` los llena la
 * pantalla de producción; hasta entonces quedan vacíos.
 */
class PedidoSalaLinea extends Model
{
    protected $table = 'pedido_sala_lineas';

    protected $fillable = ['producto_id', 'cantidad', 'cantidad_enviada', 'motivo_no_enviado'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'cantidad_enviada' => 'decimal:4', 'producto_id' => 'integer'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoSala::class, 'pedido_sala_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /** La cantidad sin ceros de más: «12», «2.5». */
    public function cantidadLegible(): string
    {
        return rtrim(rtrim(number_format((float) $this->cantidad, 4, '.', ','), '0'), '.');
    }
}
