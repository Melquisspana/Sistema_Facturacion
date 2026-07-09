<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del catálogo de EXPORTACIÓN (lista de empaque). Independiente del
 * catálogo de productos DTE; solo alimenta el módulo de exportaciones.
 */
class ExportacionProducto extends Model
{
    use HasFactory;

    protected $table = 'exportacion_productos';

    protected $fillable = [
        'codigo',
        'nombre_es',
        'nombre_en',
        'unidad',
        'unidades_por_caja',
        'gramos_por_unidad',
        'onzas_por_unidad',
        'precio_caja',
        'peso_neto_caja_kg',
        'peso_bruto_caja_kg',
        'peso_neto_caja_lb',
        'peso_bruto_caja_lb',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'unidades_por_caja' => 'integer',
            'gramos_por_unidad' => 'decimal:2',
            'onzas_por_unidad' => 'decimal:2',
            'precio_caja' => 'decimal:2',
            'peso_neto_caja_kg' => 'decimal:2',
            'peso_bruto_caja_kg' => 'decimal:2',
            'peso_neto_caja_lb' => 'decimal:2',
            'peso_bruto_caja_lb' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExportacionItem::class);
    }

    /** Campos que se copian como snapshot al agregar el producto a una exportación. */
    public function datosSnapshot(): array
    {
        return $this->only([
            'nombre_es',
            'nombre_en',
            'unidad',
            'unidades_por_caja',
            'gramos_por_unidad',
            'onzas_por_unidad',
            'precio_caja',
            'peso_neto_caja_kg',
            'peso_bruto_caja_kg',
            'peso_neto_caja_lb',
            'peso_bruto_caja_lb',
        ]);
    }

    /** Descripción combinada como aparece en el Excel: "español \ english". */
    public function descripcionCombinada(): string
    {
        return trim($this->nombre_es).' \\ '.trim($this->nombre_en);
    }

    /**
     * Precio BASE por unidad/bolsa (precio_caja / unidades_por_caja). Calculado,
     * no se guarda, para que nunca quede inconsistente con el precio de caja.
     */
    public function precioPorUnidad(): ?float
    {
        if ($this->precio_caja === null || (int) $this->unidades_por_caja < 1) {
            return null;
        }

        return round((float) $this->precio_caja / $this->unidades_por_caja, 2);
    }
}
