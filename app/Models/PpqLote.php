<?php

namespace App\Models;

use App\Enums\EstadoPpq;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lote de Prontos Pagos: agrupa CCF/NC para generar el Excel de cobro de Calleja.
 */
class PpqLote extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ppq_lotes';

    protected $fillable = [
        'referencia',
        'fecha',
        'estado',
        'cliente_id',
        'user_id',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'estado' => EstadoPpq::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PpqItem::class);
    }

    /**
     * Items en el ORDEN de cobro de Calleja: primero todos los CCF, después todas las NC; y
     * dentro de cada grupo por correlativo numérico ascendente (CCF 970, 971, 1000; NC 340, 341).
     * Una NC con número menor igual va DESPUÉS de todos los CCF.
     */
    public function itemsOrdenados(): \Illuminate\Support\Collection
    {
        return $this->items
            ->sortBy(fn (PpqItem $i) => [$i->ordenTipo(), $i->correlativoNumero()])
            ->values();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Total neto de CCF/NC del lote: CCF suma, NC resta. */
    public function totalMontoDte(): float
    {
        return round((float) $this->items->sum(fn (PpqItem $i) => $i->montoDteConSigno()), 2);
    }

    /** Total neto de albaranes del lote: CCF suma, NC resta (solo items con albarán). */
    public function totalMontoAlbaran(): float
    {
        return round((float) $this->items->sum(fn (PpqItem $i) => $i->montoAlbaranConSigno() ?? 0), 2);
    }

    /** Diferencia total: total CCF/NC menos total albarán. */
    public function diferenciaTotal(): float
    {
        return round($this->totalMontoDte() - $this->totalMontoAlbaran(), 2);
    }

    /** Items sin albarán vinculado (marcados como tales o sin monto de albarán). */
    public function cantidadSinAlbaran(): int
    {
        return $this->items->filter(fn (PpqItem $i) => $i->sin_albaran || $i->monto_albaran === null)->count();
    }

    /** Items con albarán cuyo monto difiere del CCF/NC más allá de la tolerancia. */
    public function cantidadConDiferencia(): int
    {
        $tolerancia = (float) config('ppq.diferencia_coincide', 0.05);

        return $this->items
            ->filter(fn (PpqItem $i) => $i->monto_albaran !== null && abs((float) $i->diferencia) > $tolerancia)
            ->count();
    }

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }
}
