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

    /** ¿Vino del «Facturar» directo de la lista (24/11/2026), sin pasar por el formulario? */
    public function esDirecto(): bool
    {
        return $this->boolean('directo');
    }

    /**
     * El formulario del CCF de este pedido: ahí se ve lo que frenó y se corrige. Desde la
     * ficha del pedido (`desde=pedido`), «Cancelar» vuelve a la ficha.
     */
    public function formulario(): string
    {
        return route('facturacion.create-ccf', ['pedido' => $this->route('pedido')->id]
            + ($this->input('desde') === 'pedido' ? ['desde' => 'pedido'] : []));
    }

    /** Lo que no pasa, si vino directo, se muestra en el formulario del pedido y no en la lista. */
    protected function getRedirectUrl(): string
    {
        return $this->esDirecto() ? $this->formulario() : parent::getRedirectUrl();
    }
}
