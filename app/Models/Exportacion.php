<?php

namespace App\Models;

use App\Enums\TipoDte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'dte_id',
        'cliente_nombre',
        'cliente_direccion',
        'exportador_nombre',
        'exportador_direccion',
        'fecha',
        'factura',
        'fda_reg_number',
        'observaciones',
        'estado',
        'archivada',
        'archivada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'archivada' => 'boolean',
            'archivada_en' => 'datetime',
        ];
    }

    /**
     * ¿Las observaciones marcan esta lista como una prueba (APITEST/no real)?
     * Solo lectura de texto libre, para poder mostrar "Prueba APITEST" en el
     * badge sin depender de una columna nueva dedicada a ese matiz.
     */
    public function esPruebaApitest(): bool
    {
        return str_contains(mb_strtoupper((string) $this->observaciones), 'APITEST');
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

    public function estaAprobada(): bool
    {
        return $this->estado === 'aprobada';
    }

    /**
     * Factura de Exportación (FEX) creada a partir de esta lista. Nullable: la
     * mayoría de listas todavía no tienen una. NO se crea/copia nada todavía;
     * esta relación solo prepara la infraestructura de vínculo.
     */
    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    /**
     * True solo si hay un DTE vinculado Y es realmente una Factura de Exportación
     * (tipo 11). Comprobación defensiva: dte_id podría en teoría apuntar a un
     * registro que ya no exista o (por error futuro) a otro tipo de documento.
     */
    public function tieneFex(): bool
    {
        return $this->dte_id !== null && $this->dte?->tipo_dte === TipoDte::FacturaExportacion;
    }

    /**
     * Líneas para PREPARAR la factura de exportación, calculadas EN VIVO desde el
     * snapshot de los items (no relee precios del catálogo). Es un ayudante para
     * copiar/armar la factura en Conta Portable: NO es un DTE ni se persiste.
     *
     * @return list<array{descripcion: string, cantidad: int, precio_unitario: float, total: float}>
     */
    public function lineasFactura(): array
    {
        return $this->items
            ->map(fn (ExportacionItem $i) => [
                'descripcion' => $i->descripcionFactura(),
                'cantidad' => (int) $i->cantidad_cajas,
                'precio_unitario' => (float) $i->precio_caja,
                'total' => $i->valorTotal(),
            ])
            ->all();
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
