<?php

namespace App\Models;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\FormaCobroEntrega;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Services\Rutas\DiaVendedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Un CCF que viajó en una salida y qué pasó con él. Un intento por fila: si sobró, en la
 * próxima salida es otra fila. Ver la migración para el porqué de cada columna.
 */
class SalidaRutaEntrega extends Model
{
    use LogsActivity;

    protected $table = 'salida_ruta_entregas';

    protected $fillable = [
        'salida_ruta_id',
        'dte_id',
        'cliente_sucursal_id',
        'resultado',
        'motivo_no_entrega',
        'nota',
        'trae_nota_averia',
        'entregado_por_id',
        'fecha_resultado',
        'origen_registro',
        'registrado_por',
        'forma_cobro',
        'monto_esperado',
        'monto_cobrado',
        'quedan_numero',
        'quedan_fecha_pago',
    ];

    protected function casts(): array
    {
        return [
            'forma_cobro' => FormaCobroEntrega::class,
            'monto_esperado' => 'decimal:2',
            'monto_cobrado' => 'decimal:2',
            'resultado' => ResultadoEntrega::class,
            'motivo_no_entrega' => MotivoNoEntrega::class,
            'origen_registro' => OrigenRegistroEntrega::class,
            'trae_nota_averia' => 'boolean',
            'fecha_resultado' => 'datetime',
            'quedan_fecha_pago' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['resultado', 'motivo_no_entrega', 'nota', 'trae_nota_averia', 'entregado_por_id', 'fecha_resultado', 'origen_registro', 'forma_cobro', 'monto_cobrado', 'quedan_numero', 'quedan_fecha_pago'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('salida_ruta_entrega')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => $this->esFacturadoDespues() ? 'anotó el CCF facturado después de entregar' : 'cargó el CCF a la salida',
                'updated' => 'registró la entrega del CCF',
                'deleted' => $this->esFacturadoDespues() ? 'deshizo el CCF facturado después de entregar' : 'quitó el CCF de la salida',
                default => $evento,
            });
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'dte_id');
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class, 'cliente_sucursal_id');
    }

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'entregado_por_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function estaPendiente(): bool
    {
        return $this->resultado === null;
    }

    /** Anotado por la oficina después de facturar: entregado, pero sin dinero en la salida. */
    public function esFacturadoDespues(): bool
    {
        return $this->origen_registro === OrigenRegistroEntrega::FacturadoDespues;
    }

    /**
     * Lo que viajó y se registró en la ruta. Deja afuera los facturados después de entregar,
     * que no entran en el cierre del día ni en la liquidación.
     */
    public function scopeSinFacturadosDespues(Builder $q): Builder
    {
        return $q->where(fn (Builder $q) => $q->whereNull('origen_registro')
            ->orWhere('origen_registro', '!=', OrigenRegistroEntrega::FacturadoDespues->value));
    }

    /**
     * Entregadas con quedan: lo que queda por cobrar. Primero las que no tienen fecha de pago
     * (la tienda no la dio: hay que averiguarla); después, el pago más próximo. Sale cuando
     * «Facturas por cobrar» da la factura por pagada. Lo lee la pestaña «Viajes» del tablero.
     *
     * Con quedan es: la que trae número o fecha de pago, o la que el vendedor marcó «Quedan»
     * al entregar en su viaje o en la automática (ahí siempre dice cómo le pagaron). Quedan
     * afuera el supermercado que cobra por el portal y las salidas armadas a mano de antes,
     * donde «sin cobro» era el crédito habitual del cliente.
     */
    public function scopeQuedanPorCobrar(Builder $q): Builder
    {
        $portal = DiaVendedor::clientePortal();

        return $q->entregadas()->whereNull('forma_cobro')
            ->where(fn (Builder $q) => $q->whereNotNull('quedan_fecha_pago')->orWhereNotNull('quedan_numero')
                ->orWhere(fn (Builder $q) => $q->where('origen_registro', OrigenRegistroEntrega::Vendedor->value)
                    ->whereHas('salida', fn (Builder $s) => $s->automaticas())
                    ->when($portal !== null, fn (Builder $q) => $q->whereDoesntHave('dte', fn (Builder $d) => $d->withTrashed()->where('cliente_id', $portal)))))
            ->whereNotExists(fn ($pagada) => $pagada->selectRaw('1')->from('cobro_documentos')
                ->whereColumn('cobro_documentos.dte_id', 'salida_ruta_entregas.dte_id')
                ->where('cobro_documentos.pago_estado', EstadoPagoCobro::Pagado->value))
            ->orderByRaw('CASE WHEN salida_ruta_entregas.quedan_fecha_pago IS NULL THEN 0 ELSE 1 END')
            ->orderBy('quedan_fecha_pago');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereNull('resultado');
    }

    public function scopeEntregadas(Builder $q): Builder
    {
        return $q->where('resultado', ResultadoEntrega::Entregado->value);
    }
}
