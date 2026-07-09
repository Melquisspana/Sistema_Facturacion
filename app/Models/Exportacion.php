<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exportación / lista de empaque. Documento administrativo (NO es DTE): agrupa
 * items snapshot y genera el Excel con la plantilla oficial.
 */
class Exportacion extends Model
{
    use HasFactory;

    protected $table = 'exportaciones';

    protected $fillable = [
        'exportacion_cliente_id',
        'cliente_nombre',
        'cliente_direccion',
        'exportador_nombre',
        'exportador_direccion',
        'fecha',
        'factura',
        'fda_reg_number',
        'observaciones',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExportacionItem::class);
    }

    /** Cliente de exportación de referencia; el encabezado usa el snapshot de texto. */
    public function cliente(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExportacionCliente::class, 'exportacion_cliente_id');
    }

    public function totalCajas(): int
    {
        return (int) $this->items->sum('cantidad_cajas');
    }

    public function totalUnidades(): int
    {
        return (int) $this->items->sum(fn (ExportacionItem $i) => $i->totalUnidades());
    }

    public function valorTotal(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->valorTotal()), 2);
    }

    public function pesoNetoTotalKg(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoNetoTotalKg()), 2);
    }

    public function pesoBrutoTotalKg(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoBrutoTotalKg()), 2);
    }

    public function pesoNetoTotalLb(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoNetoTotalLb()), 2);
    }

    public function pesoBrutoTotalLb(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoBrutoTotalLb()), 2);
    }
}
