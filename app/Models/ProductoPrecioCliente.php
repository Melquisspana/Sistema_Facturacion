<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Precio especial de un producto para un cliente y/o sucursal. Ver migración.
 *
 * Auditoría: registra alta/edición/baja con spatie/activitylog (precio, vigencia,
 * activación, cliente/sucursal), solo atributos que cambian. Sin datos sensibles.
 */
class ProductoPrecioCliente extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'producto_precios_cliente';

    protected $fillable = [
        'producto_id',
        'cliente_id',
        'cliente_sucursal_id',
        'precio',
        'activo',
        'fecha_inicio',
        'fecha_fin',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:4',
            'activo' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('precio_producto')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó un precio especial',
                'updated' => 'actualizó un precio especial',
                'deleted' => 'eliminó un precio especial',
                default => $evento,
            });
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function clienteSucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class);
    }
}
