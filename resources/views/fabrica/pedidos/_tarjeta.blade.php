{{-- Un pedido de «Por mandar»: un botón grande con el nombre de la sala y una línea chica
     (zona, cuántos productos faltan, hora). Sin productos ni botones de mandar: se toca el
     nombre y se abre el pedido (12/11/2026). Pantalla táctil de fábrica
     (docs/GUIA_PANTALLAS.md, «Pantalla táctil»): letra text-2xl, todo el recuadro se toca.

     En «Listos para salir» (24/11/2026) es la misma tarjeta, sin «Armando»; el resto de un
     envío anterior sigue diciendo «Mandado en parte».

     Variables: $pedido (con lo de PedidosFabrica::relaciones()), $atrasado (bool), $hoy (Y-m-d). --}}
@php
    $cuantos = $pedido->lineas->filter->tienePendiente()->count();
    $dias = (int) $pedido->fecha->diffInDays(\Illuminate\Support\Carbon::parse($hoy));
@endphp
<a id="pedido-{{ $pedido->id }}" href="{{ route('fabrica.pedidos.show', $pedido) }}"
   class="block min-h-12 rounded-2xl border border-gray-200 bg-white p-5 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
    <span class="flex flex-wrap items-start justify-between gap-2">
        <span class="min-w-0 text-2xl font-bold text-gray-800">{{ $pedido->nombreSala() }}</span>
        <span class="flex flex-wrap items-center gap-2">
            @if ($pedido->origen === \App\Enums\OrigenPedidoSala::Web)
                @include('fabrica.pedidos._web-badges')
            @endif
            @if ($pedido->armando_desde && $pedido->estado !== \App\Enums\EstadoPedidoSala::Listo)
                <span class="inline-block rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Armando</span>
            @endif
            @if ($pedido->enParte())
                <x-rutas.estado-badge :estado="\App\Enums\EstadoPedidoSala::Parcial" />
            @endif
        </span>
    </span>
    <span class="mt-1 block text-sm text-gray-600">
        @if ($pedido->origen === \App\Enums\OrigenPedidoSala::Web)
            {{ $pedido->pedidoWeb->entrega_tipo === 'recoger' ? 'Para recoger' : 'Con ruta · '.$pedido->zonaNombre() }} · {{ collect($pedido->pedidoWeb->cajitas_detalle)->sum('cantidad') }} cajitas · {{ $pedido->pedidoWeb->pagado_en->format('H:i') }}
        @else
        {{ $pedido->zonaNombre() }} · {{ $cuantos === 1 ? '1 producto' : $cuantos.' productos' }} · {{ $pedido->created_at->format('H:i') }}
        @endif
    </span>
    {{-- Los de días anteriores van bajo su propio título; la tarjeta es igual a las de hoy y
         solo agrega la fecha en gris (Melqui, 17/11/2026: el amarillo era demasiado vistoso). --}}
    @if ($atrasado)
        <span class="mt-1 block text-sm text-gray-500">Del {{ $pedido->fecha->format('d/m/Y') }} · hace {{ $dias === 1 ? '1 día' : $dias.' días' }}</span>
    @endif
    {{-- El vendedor dejó notas (por ejemplo, cambios por avería): se leen al abrir el pedido. --}}
    @if (filled($pedido->notas))
        <span class="mt-2 inline-block rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Con notas del vendedor</span>
    @endif
</a>
