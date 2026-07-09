<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de una exportación: SNAPSHOT del producto del catálogo al momento de
 * agregarlo (cambios posteriores del catálogo no afectan exportaciones viejas).
 */
class ExportacionItem extends Model
{
    use HasFactory;

    protected $table = 'exportacion_items';

    protected $fillable = [
        'exportacion_id',
        'exportacion_producto_id',
        'cantidad_cajas',
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
    ];

    protected function casts(): array
    {
        return [
            'cantidad_cajas' => 'integer',
            'unidades_por_caja' => 'integer',
            'gramos_por_unidad' => 'decimal:2',
            'onzas_por_unidad' => 'decimal:2',
            'precio_caja' => 'decimal:2',
            'peso_neto_caja_kg' => 'decimal:2',
            'peso_bruto_caja_kg' => 'decimal:2',
            'peso_neto_caja_lb' => 'decimal:2',
            'peso_bruto_caja_lb' => 'decimal:2',
        ];
    }

    public function exportacion(): BelongsTo
    {
        return $this->belongsTo(Exportacion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ExportacionProducto::class, 'exportacion_producto_id');
    }

    /** Descripción combinada como aparece en el Excel: "español \ english". */
    public function descripcionCombinada(): string
    {
        return trim($this->nombre_es).' \\ '.trim($this->nombre_en);
    }

    public function totalUnidades(): int
    {
        return $this->cantidad_cajas * $this->unidades_por_caja;
    }

    public function valorTotal(): float
    {
        return round($this->cantidad_cajas * (float) $this->precio_caja, 2);
    }

    public function pesoNetoTotalKg(): float
    {
        return round($this->cantidad_cajas * (float) $this->peso_neto_caja_kg, 2);
    }

    public function pesoBrutoTotalKg(): float
    {
        return round($this->cantidad_cajas * (float) $this->peso_bruto_caja_kg, 2);
    }

    public function pesoNetoTotalLb(): float
    {
        return round($this->cantidad_cajas * (float) $this->peso_neto_caja_lb, 2);
    }

    public function pesoBrutoTotalLb(): float
    {
        return round($this->cantidad_cajas * (float) $this->peso_bruto_caja_lb, 2);
    }
}
