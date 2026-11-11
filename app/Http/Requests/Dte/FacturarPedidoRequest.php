<?php

namespace App\Http\Requests\Dte;

use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Services\Rutas\FacturarPedido;
use Illuminate\Validation\ValidationException;

/**
 * «Crear borrador» desde el pedido (Facturación › Llegó de la fábrica). Hereda TODAS las
 * reglas del alta del CCF (campos, emisor único, OC obligatoria). Lo único distinto: el
 * cliente y la sala salen del pedido de la ruta, nunca del formulario.
 */
class FacturarPedidoRequest extends CrearBorradorRequest
{
    /** Antes de validar: quien no factura (la fábrica, por ejemplo) recibe 403, no errores. */
    public function authorize(): bool
    {
        $pedido = $this->route('pedido');

        return $pedido instanceof PedidoSala
            && (bool) $this->user()?->can('create', Dte::class)
            && $this->user()->can('view', $pedido);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        /** @var PedidoSala $pedido */
        $pedido = $this->route('pedido');
        // La tienda nueva sin sala: el motivo claro, no «falta el cliente». Solo a quien factura
        // (esto corre antes que authorize()); los demás reciben el 403 de siempre.
        if ($pedido->esTiendaNueva() && $this->authorize()) {
            throw ValidationException::withMessages(['pedido' => FacturarPedido::SIN_SALA]);
        }
        // La sala se borró de Clientes: lo mismo, el motivo claro y no «falta el cliente».
        if (! $pedido->esTiendaNueva() && $pedido->sala === null && $this->authorize()) {
            throw ValidationException::withMessages(['pedido' => FacturarPedido::SALA_BORRADA]);
        }
        $this->merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'cliente_id' => $pedido->sala?->cliente_id,
            'cliente_sucursal_id' => $pedido->sala?->id,
        ]);
    }
}
