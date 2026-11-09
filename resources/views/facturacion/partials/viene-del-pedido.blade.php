{{-- «Viene del pedido N (envíos 1 y 2)»: el CCF salió de «Facturar» lo que llegó de la
     fábrica. Lo usan la ficha y el editor. Sin permiso para ver el pedido, sin enlace.

     Variables: $dte con `enviosPedido` (vigentes, por número) y `enviosPedido.pedido`. --}}
@php
    $enviosDelPedido = $dte->enviosPedido;
    $pedidoOrigen = $enviosDelPedido->first()?->pedido;
@endphp
@if ($pedidoOrigen)
    @php
        $texto = 'Viene del pedido N.º '.$pedidoOrigen->id.' ('.($enviosDelPedido->count() === 1 ? 'envío ' : 'envíos ')
            .$enviosDelPedido->pluck('numero')->join(', ', ' y ').')';
    @endphp
    <p class="text-sm text-gray-600">
        @can('view', $pedidoOrigen)
            <a href="{{ route('rutas.pedidos.show', $pedidoOrigen) }}" class="text-indigo-600 underline">{{ $texto }}</a>
        @else
            {{ $texto }}
        @endcan
    </p>
@endif
