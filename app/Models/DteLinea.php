<?php

namespace App\Models;

use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Observers\DteLineaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([DteLineaObserver::class])]
class DteLinea extends Model
{
    protected $table = 'dte_lineas';

    protected $fillable = [
        'dte_id', 'numero_linea', 'producto_id',
        'codigo', 'codigo_barra', 'descripcion',
        'unidad_medida_id', 'unidad_codigo', 'unidad_nombre',
        'tipo_producto', 'tipo_impuesto',
        'cantidad', 'precio_unitario', 'descuento_monto', 'descuento_porcentaje',
        'venta_no_sujeta', 'venta_exenta', 'venta_gravada', 'venta_exportacion', 'iva_linea', 'total_linea',
        'dte_linea_original_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo_producto' => TipoProducto::class,
            'tipo_impuesto' => TipoImpuesto::class,
            'cantidad' => 'decimal:4',
            'precio_unitario' => 'decimal:6',
            'descuento_monto' => 'decimal:2',
            'descuento_porcentaje' => 'decimal:2',
            'venta_no_sujeta' => 'decimal:2',
            'venta_exenta' => 'decimal:2',
            'venta_gravada' => 'decimal:2',
            'venta_exportacion' => 'decimal:2',
            'iva_linea' => 'decimal:2',
            'total_linea' => 'decimal:2',
        ];
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    /** Línea del documento original (para nota de crédito). */
    public function lineaOriginal(): BelongsTo
    {
        return $this->belongsTo(DteLinea::class, 'dte_linea_original_id');
    }
}
