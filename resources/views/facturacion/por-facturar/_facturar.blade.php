{{-- «Facturar» de un pedido (24/11/2026), en «Llegó de pedidos» y en la ficha del pedido. Con
     una sola OC clara ($oc['directo'], FacturarPedido::vaDirecto) va directo al borrador, sin
     el formulario; si algo frena, el servidor abre el formulario con lo que falta. Si no (falta
     la OC o hay varias), el enlace al formulario, donde se escribe o se elige. El botón lleva
     las clases del principal de la guía, igual que el enlace: x-primary-button todavía tiene
     el aspecto viejo.

     Variables: $pedido, $oc (propuestas, directo), $formulario (url del formulario del CCF) y
     $desde (?string: 'pedido' desde la ficha, para que «Cancelar» del formulario vuelva ahí). --}}
@php($principal = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700')
@if ($oc['directo'])
    <form method="POST" action="{{ route('facturacion.pedidos.facturar', $pedido) }}">
        @csrf
        <input type="hidden" name="numero_orden_compra" value="{{ $oc['propuestas'][0] ?? '' }}">
        <input type="hidden" name="directo" value="1">
        @if (($desde ?? null) === 'pedido')
            <input type="hidden" name="desde" value="pedido">
        @endif
        <button type="submit" class="{{ $principal }} w-full">Facturar</button>
    </form>
@else
    <a href="{{ $formulario }}" class="{{ $principal }}">Facturar</a>
@endif
