<?php

namespace App\Models;

use App\Enums\EstadoPedidoSala;
use App\Services\Rutas\PedidosSala;
use App\Support\Dte\ReglaOrdenCompra;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido que un vendedor saca en una sala: lo que anota en el sugerido que
 * firma el gerente. La regla (quién, cuándo, qué se valida) vive en
 * {@see PedidosSala}, que también deja la auditoría (con las líneas);
 * ver la migración para cada columna.
 */
class PedidoSala extends Model
{
    /** Tope de cada cantidad: cabe en `decimal(11,4)` y ninguna sala pide más. */
    public const CANTIDAD_MAXIMA = 99999;

    protected $table = 'pedidos_sala';

    protected $fillable = [
        'cliente_sucursal_id',
        'sala_nueva_nombre',
        'ruta_id',
        'salida_ruta_id',
        'sacado_por_id',
        'registrado_por',
        'fecha',
        'estado',
        'numero_orden_compra',
        'notas',
        'armando_desde',
        'armando_por',
        'armando_sigue',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'estado' => EstadoPedidoSala::class,
            'sacado_por_id' => 'integer',
            'registrado_por' => 'integer',
            'armando_desde' => 'datetime',
            'armando_por' => 'integer',
            'armando_sigue' => 'boolean',
        ];
    }

    /** La sala del pedido. Vacía en la tienda nueva hasta que la oficina la enlaza ({@see esTiendaNueva()}). */
    public function sala(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class, 'cliente_sucursal_id');
    }

    /**
     * La tienda que el vendedor anotó solo con el nombre (10/11/2026): todavía sin cliente ni
     * sala. No se factura hasta que la oficina la da de alta y la enlaza.
     */
    public function esTiendaNueva(): bool
    {
        return $this->cliente_sucursal_id === null;
    }

    /**
     * Cómo se nombra el pedido en las pantallas, la hoja y el correo: la sala o, si todavía
     * no tiene, el nombre que escribió el vendedor con «(tienda nueva)».
     */
    public function nombreSala(): string
    {
        if (! $this->esTiendaNueva()) {
            return (string) ($this->sala?->nombre ?? 'Sala sin nombre');
        }

        return ($this->sala_nueva_nombre ?? 'Tienda sin nombre').' (tienda nueva)';
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class, 'ruta_id');
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function sacadoPor(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'sacado_por_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoSalaLinea::class, 'pedido_sala_id');
    }

    public function armandoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'armando_por');
    }

    /** Las salidas de la fábrica, también las anuladas. */
    public function envios(): HasMany
    {
        return $this->hasMany(PedidoSalaEnvio::class, 'pedido_sala_id');
    }

    public function estaRegistrado(): bool
    {
        return $this->estado === EstadoPedidoSala::Registrado;
    }

    /** Todo menos anulado. */
    public function estaVigente(): bool
    {
        return $this->estado !== EstadoPedidoSala::Anulado;
    }

    /** Registrado o parcial: le falta salir de la fábrica. */
    public function estaPorMandar(): bool
    {
        return $this->estado->porMandar();
    }

    /**
     * ¿La fábrica ya lo tocó? Algún envío (aunque esté anulado), un estado distinto de
     * Pendiente o el armado empezado. Usa `envios_exists` si vino precargado (withExists).
     */
    public function fabricaEmpezo(): bool
    {
        return ! $this->estaRegistrado() || $this->armando_desde !== null || $this->tieneEnvios();
    }

    /** Algún envío, también los anulados. */
    public function tieneEnvios(): bool
    {
        if (array_key_exists('envios_exists', $this->attributes)) {
            return (bool) $this->attributes['envios_exists'];
        }

        return $this->relationLoaded('envios') ? $this->envios->isNotEmpty() : $this->envios()->exists();
    }

    /** ¿Le falta algo por mandar? Con las líneas y sus envíos vigentes ya cargados. */
    public function faltaAlgo(): bool
    {
        return $this->lineas->contains(fn (PedidoSalaLinea $l) => $l->tienePendiente());
    }

    /**
     * Huella de lo que importa para mandar: el estado, las líneas (id y cantidad) y los
     * envíos vigentes. Detecta la corrección del vendedor (cambian los id de las líneas),
     * otra tableta que mandó primero y un envío anulado. El borrador NO entra: guardarlo
     * no invalida la tarjeta de otra tableta. Se calcula con las relaciones ya cargadas.
     */
    public function versionProduccion(): string
    {
        $lineas = $this->lineas->sortBy('id')->map(fn (PedidoSalaLinea $l) => [$l->id, (string) $l->cantidad])->values()->all();
        $envios = $this->envios->whereNull('anulado_en')->pluck('id')->sort()->values()->all();

        return hash('sha256', json_encode([$this->estado->value, $lineas, $envios]));
    }

    /**
     * Qué no cuadra en la forma de la OC (#169). Solo avisa: el pedido se guarda igual.
     * Vacío si no tiene OC (es opcional) o si cuadra.
     *
     * @return list<string>
     */
    public function avisosOrdenCompra(): array
    {
        return filled($this->numero_orden_compra)
            ? ReglaOrdenCompra::problemasForma($this->numero_orden_compra, $this->fecha, 'el pedido')
            : [];
    }

    /** Todo menos anulado: el pedido sacado cuenta aunque la fábrica ya lo haya marcado. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('estado', '!=', EstadoPedidoSala::Anulado->value);
    }

    public function scopePorMandar(Builder $q): Builder
    {
        return $q->whereIn('estado', EstadoPedidoSala::valoresPorMandar());
    }
}
