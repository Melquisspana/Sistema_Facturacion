<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un producto de un pedido de sala.
 *
 *  - Lo mandado sale SOLO de la suma de las líneas de los envíos vigentes
 *    ({@see mandado()}); la columna `cantidad_enviada` quedó sin uso, en NULL.
 *  - `motivo_no_enviado`: por qué no va lo que falta, opcional. Lo escribe la fábrica.
 *  - `va_borrador`: lo que la fábrica va marcando en la hoja antes de mandar.
 *
 * Las cuentas van con bcmath a escala 4, nunca con float.
 */
class PedidoSalaLinea extends Model
{
    protected $table = 'pedido_sala_lineas';

    protected $fillable = ['producto_id', 'cantidad', 'motivo_no_enviado', 'va_borrador'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:4', 'va_borrador' => 'decimal:4', 'producto_id' => 'integer'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoSala::class, 'pedido_sala_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /** Lo que llevaron los envíos vigentes de esta línea. */
    public function envioLineasVigentes(): HasMany
    {
        return $this->hasMany(PedidoSalaEnvioLinea::class, 'pedido_sala_linea_id')
            ->whereHas('envio', fn (Builder $q) => $q->whereNull('anulado_en'));
    }

    /** Lo que ya salió, con la relación cargada (sin consultar por línea si viene precargada). */
    public function mandado(): string
    {
        $total = '0.0000';
        foreach ($this->envioLineasVigentes as $l) {
            $total = bcadd($total, (string) $l->cantidad, 4);
        }

        return $total;
    }

    /** Lo que falta mandar: lo pedido menos lo mandado. */
    public function pendiente(): string
    {
        return bcsub((string) $this->cantidad, $this->mandado(), 4);
    }

    public function tienePendiente(): bool
    {
        return bccomp($this->pendiente(), '0', 4) === 1;
    }

    /** La cantidad sin ceros de más: «12», «2.5». */
    public function cantidadLegible(): string
    {
        return self::legible((string) $this->cantidad);
    }

    /** Cualquier cantidad a escala 4 sin ceros de más, con separador de miles. */
    public static function legible(string $cantidad): string
    {
        return rtrim(rtrim(number_format((float) $cantidad, 4, '.', ','), '0'), '.');
    }
}
