<?php

namespace App\Models;

use App\Support\OrdenCompra;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Albarán de Calleja. Se vincula al CCF/NC por número de orden de compra; la sala
 * se deriva de la OC. La carga manual es la fase 1; los campos origen/gmail/archivo
 * quedan listos para la importación desde Gmail (fase 2).
 */
class PpqAlbaran extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ppq_albaranes';

    protected $fillable = [
        'numero_albaran',
        'fecha_albaran',
        'monto_albaran',
        'numero_orden_compra',
        'sala_codigo',
        'cliente_sucursal_id',
        'dte_id',
        'origen',
        'gmail_message_id',
        'archivo_path',
    ];

    protected function casts(): array
    {
        return [
            'fecha_albaran' => 'date',
            'monto_albaran' => 'decimal:2',
        ];
    }

    /** Al guardar, deriva la sala desde la OC si no viene seteada. */
    protected static function booted(): void
    {
        static::saving(function (PpqAlbaran $albaran) {
            if (blank($albaran->sala_codigo) && filled($albaran->numero_orden_compra)) {
                $albaran->sala_codigo = OrdenCompra::salaDesde($albaran->numero_orden_compra);
            }
        });
    }

    public function clienteSucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PpqItem::class);
    }

    /** ¿Ya fue vinculado a algún item de algún lote? (anti-duplicado de albaranes) */
    public function yaVinculado(): bool
    {
        return $this->items()->exists();
    }
}
