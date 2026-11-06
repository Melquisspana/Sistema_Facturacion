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
        'ruta_id',
        'salida_ruta_id',
        'sacado_por_id',
        'registrado_por',
        'fecha',
        'estado',
        'numero_orden_compra',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'estado' => EstadoPedidoSala::class,
            'sacado_por_id' => 'integer',
            'registrado_por' => 'integer',
        ];
    }

    public function sala(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class, 'cliente_sucursal_id');
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

    public function estaRegistrado(): bool
    {
        return $this->estado === EstadoPedidoSala::Registrado;
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

    public function scopeRegistrados(Builder $q): Builder
    {
        return $q->where('estado', EstadoPedidoSala::Registrado->value);
    }
}
