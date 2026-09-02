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

    /**
     * Descripción para la factura de exportación: "nombre_es / nombre_en - N units"
     * (N = unidades por caja del snapshot). Si no hay unidades por caja, se omite
     * el sufijo. Ej.: "Caja de dulce de nance / Yellow cherry candy - 144 units".
     *
     * NO REPITE EL SUFIJO. En el catálogo real los 48 productos ya traen el número
     * de unidades dentro de `nombre_en` («Cashew seed baked - 216 units»), así que
     * añadirlo sin mirar producía líneas de factura como «… - 216 units - 216
     * units». Nunca reventó porque hasta hoy ninguna lista llegó a facturarse, pero
     * habría salido impreso en la primera FEX real.
     *
     * La comprobación es sobre el sufijo YA COMPUESTO, no un `str_contains` de la
     * palabra «units»: un producto cuyo nombre mencione unidades por otro motivo
     * («200 units per case, 12 units free») sigue recibiendo su sufijo correcto.
     */
    public function descripcionFactura(): string
    {
        $base = trim($this->nombre_es).' / '.trim($this->nombre_en);
        $unidades = (int) $this->unidades_por_caja;

        if ($unidades < 1) {
            return $base;
        }

        $sufijo = $unidades.' units';

        return str_ends_with(mb_strtolower($base), mb_strtolower($sufijo))
            ? $base
            : $base.' - '.$sufijo;
    }

    public function totalUnidades(): int
    {
        return $this->cantidad_cajas * $this->unidades_por_caja;
    }

    /** Precio por unidad/bolsa del SNAPSHOT (precio usado / unidades por caja). */
    public function precioPorUnidad(): ?float
    {
        if ((int) $this->unidades_por_caja < 1) {
            return null;
        }

        return round((float) $this->precio_caja / $this->unidades_por_caja, 2);
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
