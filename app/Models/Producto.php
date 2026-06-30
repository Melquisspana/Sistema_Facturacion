<?php

namespace App\Models;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Producto extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'codigo',
        'codigo_barra',
        'nombre',
        'descripcion',
        'tipo_producto',
        'unidad_medida_id',
        'precio_unitario',
        'tipo_impuesto',
        'maneja_inventario',
        'producto_inventario_ref',
        'observaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_producto' => TipoProducto::class,
            'tipo_impuesto' => TipoImpuesto::class,
            'precio_unitario' => 'decimal:4',
            'maneja_inventario' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('producto')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el producto',
                'updated' => 'actualizó el producto',
                'deleted' => 'eliminó el producto',
                default => $evento,
            });
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    /** Precios especiales por cliente/sucursal. */
    public function preciosCliente(): HasMany
    {
        return $this->hasMany(ProductoPrecioCliente::class);
    }
}
