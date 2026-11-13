{{-- Un pedido de «Por mandar»: un botón grande con el nombre de la sala y una línea chica
     (zona, cuántos productos faltan, hora). Sin productos ni botones de mandar: se toca el
     nombre y se abre el pedido (12/11/2026). Pantalla táctil de fábrica
     (docs/GUIA_PANTALLAS.md, «Pantalla táctil»): letra text-2xl, todo el recuadro se toca.

     Variables: $pedido (con lo de PedidosFabrica::relaciones()), $atrasado (bool), $hoy (Y-m-d). --}}
@php
    $cuantos = $pedido->lineas->filter->tienePendiente()->count();
    $dias = (int) $pedido->fecha->diffInDays(\Illuminate\Support\Carbon::parse($hoy));
@endphp
<a id="pedido-{{ $pedido->id }}" href="{{ route('fabrica.pedidos.show', $pedido) }}"
   class="block min-h-12 rounded-2xl border p-5 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $atrasado ? 'border-amber-300 bg-amber-50 ring-1 ring-amber-300' : 'border-gray-200 bg-white' }}">
    <span class="flex flex-wrap items-start justify-between gap-2">
        <span class="min-w-0 text-2xl font-bold text-gray-800">{{ $pedido->nombreSala() }}</span>
        <span class="flex flex-wrap items-center gap-2">
            @if ($pedido->armando_desde)
                <span class="inline-block rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700">Armando</span>
            @endif
            @if ($pedido->estado === \App\Enums\EstadoPedidoSala::Parcial)
                <x-rutas.estado-badge :estado="$pedido->estado" />
            @endif
        </span>
    </span>
    <span class="mt-1 block text-sm text-gray-600">
        {{ $pedido->zonaNombre() }} · {{ $cuantos === 1 ? '1 producto' : $cuantos.' productos' }} · {{ $pedido->created_at->format('H:i') }}
    </span>
    @if ($atrasado)
        <span class="mt-1 block text-sm font-semibold text-amber-800">Del {{ $pedido->fecha->format('d/m/Y') }} · hace {{ $dias === 1 ? '1 día' : $dias.' días' }}</span>
    @endif
</a>
