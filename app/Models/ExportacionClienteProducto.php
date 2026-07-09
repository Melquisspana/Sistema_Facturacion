<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación producto→cliente de exportación con su precio específico.
 * Único por (cliente, producto): el mismo producto no se duplica en el cliente.
 */
class ExportacionClienteProducto extends Model
{
    use HasFactory;

    protected $table = 'exportacion_cliente_productos';

    protected $fillable = [
        'exportacion_cliente_id',
        'exportacion_producto_id',
        'precio_caja',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_caja' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ExportacionCliente::class, 'exportacion_cliente_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ExportacionProducto::class, 'exportacion_producto_id');
    }

    /**
     * Precio del CLIENTE por unidad/bolsa (precio_caja / unidades_por_caja del
     * producto). Calculado, no se guarda, para evitar inconsistencias.
     */
    public function precioPorUnidad(): ?float
    {
        $unidades = (int) ($this->producto?->unidades_por_caja ?? 0);
        if ($unidades < 1) {
            return null;
        }

        return round((float) $this->precio_caja / $unidades, 2);
    }
}
