<?php

namespace App\Models;

use App\Support\NumeroAlbaran;
use App\Support\OrdenCompra;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
        'tipo_codigo',
        'fecha_albaran',
        'monto_albaran',
        'numero_orden_compra',
        'sala_codigo',
        'cliente_sucursal_id',
        'dte_id',
        'origen',
        'gmail_message_id',
        'archivo_path',
        'archivo_nombre',
        'archivo_hash',
        'archivo_descargado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_albaran' => 'date',
            'monto_albaran' => 'decimal:2',
            'archivo_descargado_en' => 'datetime',
        ];
    }

    /**
     * Al guardar, deriva de lo que la propia fila ya dice: la sala desde la OC y el TIPO
     * desde el número canónico. Solo RELLENA; nunca pisa un valor que alguien puso.
     *
     * El tipo se lee con {@see NumeroAlbaran::desde()}, que es el único lugar del sistema
     * que sabe desarmar `AC01/0236/00/6359`. Si el número no trae prefijo reconocible
     * —los capturados a mano con el número suelto— el tipo queda NULL, y eso significa
     * «no consta», nunca «AC01».
     */
    protected static function booted(): void
    {
        static::saving(function (PpqAlbaran $albaran) {
            if (blank($albaran->sala_codigo) && filled($albaran->numero_orden_compra)) {
                $albaran->sala_codigo = OrdenCompra::salaDesde($albaran->numero_orden_compra);
            }

            if (blank($albaran->tipo_codigo) && filled($albaran->numero_albaran)) {
                $albaran->tipo_codigo = NumeroAlbaran::desde($albaran->numero_albaran)?->tipo;
            }
        });
    }

    /**
     * El código con el que el cliente identifica un albarán de ENTREGA. Es configuración,
     * no una constante del sistema: otra cadena puede llamarlo distinto, y en ningún caso
     * se compara por nombre de cliente.
     */
    public static function tipoDeEntrega(): string
    {
        return strtoupper((string) config('ppq.albaranes.tipo_entrega', 'AC01'));
    }

    /**
     * ¿Este albarán prueba una ENTREGA?
     *
     * Un albarán de crédito (avería, devolución) NO prueba una entrega: acredita mercadería
     * que volvió. Y un albarán sin tipo tampoco: no se sabe qué es, y suponerlo de entrega
     * es justo el error caro. Los dos casos responden que no, por motivos distintos que
     * ResolucionAlbaran sí distingue.
     */
    public function esDeEntrega(): bool
    {
        return filled($this->tipo_codigo)
            && strtoupper((string) $this->tipo_codigo) === self::tipoDeEntrega();
    }

    /** Solo los albaranes que prueban entrega. */
    public function scopeDeEntrega(Builder $query): Builder
    {
        return $query->where('tipo_codigo', self::tipoDeEntrega());
    }

    /** Albaranes cuyo tipo no se pudo determinar: van a excepción, no se suponen de entrega. */
    public function scopeSinTipo(Builder $query): Builder
    {
        return $query->whereNull('tipo_codigo');
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

    /**
     * Albaranes cuya SALA quedó sin resolver: ni existe en `cliente_sucursales` (por eso
     * `cliente_sucursal_id` está vacío) ni figura en el mapa auxiliar `ppq_salas`. Son las
     * EXCEPCIONES de la sincronización, para revisión manual: el sistema NUNCA da de alta
     * una sucursal por su cuenta, así que se guardan con el código tal cual y se listan acá.
     */
    public function scopeSalaSinResolver(Builder $query): Builder
    {
        return $query
            ->whereNull('cliente_sucursal_id')
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from('ppq_salas')
                ->whereColumn('ppq_salas.codigo', 'ppq_albaranes.sala_codigo'));
    }
}
