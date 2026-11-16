<?php

namespace App\Models;

use App\Enums\EstadoPedidoWeb;
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
    ];

    protected $hidden = ['cliente_nombre', 'cliente_correo', 'cliente_telefono', 'direccion', 'notas', 'regalo'];

    protected function casts(): array
    {
        return [
            'web_id' => 'integer',
            'total_centavos' => 'integer',
            'pagado_en' => 'datetime',
            'informado_preparando_en' => 'datetime',
            'estado' => EstadoPedidoWeb::class,
            'cliente_telefono' => 'encrypted',
            'direccion' => 'encrypted',
            'notas' => 'encrypted',
            'regalo' => 'encrypted:array',
            'cajitas_detalle' => 'array',
            'lineas' => 'array',
        ];
    }

    public function pedidoSala(): BelongsTo
    {
        return $this->belongsTo(PedidoSala::class);
    }
}
