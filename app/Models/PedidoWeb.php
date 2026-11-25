<?php

namespace App\Models;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoWeb;
use App\Enums\TipoDte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoWeb extends Model
{
    protected $table = 'pedidos_web';

    protected $fillable = [
        'web_id', 'codigo', 'idioma', 'pagado_en', 'alerta', 'total_centavos', 'moneda',
        'cliente_nombre', 'cliente_correo', 'cliente_telefono', 'entrega_tipo',
        'departamento_codigo', 'municipio', 'direccion', 'notas', 'regalo',
        'cajitas_detalle', 'lineas', 'pasarela', 'referencia', 'estado',
        'motivo_revision', 'pedido_sala_id', 'informado_preparando_en',
        'dte_id', 'factura_correo_en', 'informado_factura_en',
        'lista_en', 'entregado_en', 'estado_por_informar', 'estado_informado_en',
    ];

    protected $hidden = ['cliente_nombre', 'cliente_correo', 'cliente_telefono', 'direccion', 'notas', 'regalo'];

    protected function casts(): array
    {
        return [
            'web_id' => 'integer',
            'total_centavos' => 'integer',
            'pagado_en' => 'datetime',
            'informado_preparando_en' => 'datetime',
            'factura_correo_en' => 'datetime',
            'informado_factura_en' => 'datetime',
            'lista_en' => 'datetime',
            'entregado_en' => 'datetime',
            'estado_informado_en' => 'datetime',
            'estado' => EstadoPedidoWeb::class,
            'cliente_telefono' => 'encrypted',
            'direccion' => 'encrypted',
            'notas' => 'encrypted',
            'regalo' => 'encrypted:array',
            'cajitas_detalle' => 'array',
            'lineas' => 'array',
        ];
    }

    /** Una factura que sigue «facturando», o un pedido pagado sin factura, pasado este tiempo está trabado. */
    public const MINUTOS_TRABADO = 30;

    /**
     * Lo que la oficina tiene que mirar: «Requieren atención» en la lista y el aviso de
     * pedidos web. Para revisar, alertas, avisos rechazados, la factura que sigue
     * «facturando» y el pedido importado que no se facturó. Por updated_at: si la factura
     * falla se revierte sin tocar el pedido, y Reintentar sí lo mueve, así un pedido viejo
     * recién reintentado no cuenta como trabado.
     *
     * @param  Builder<PedidoWeb>  $q
     */
    public function scopeRequiereAtencion(Builder $q): Builder
    {
        $trabado = now()->subMinutes(self::MINUTOS_TRABADO);

        return $q->where(fn (Builder $q) => $q->where('estado', EstadoPedidoWeb::ParaRevisar)
            ->orWhere('alerta', '<>', '')->orWhereNotNull('motivo_revision')
            ->orWhere(fn (Builder $q) => $q->whereIn('estado', [EstadoPedidoWeb::Facturando, EstadoPedidoWeb::Importado])
                ->where('updated_at', '<', $trabado)));
    }

    public function pedidoSala(): BelongsTo
    {
        return $this->belongsTo(PedidoSala::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function facturaLista(): bool
    {
        return $this->estado === EstadoPedidoWeb::Facturado
            && $this->dte?->estado === EstadoDte::Aceptado
            && $this->dte?->tipo_dte === TipoDte::Factura;
    }
}
