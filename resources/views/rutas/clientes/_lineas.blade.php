{{-- Lo que se pidió en un pedido: cuántas cajas de cada producto, en el orden en que se pidió.
     Sin precios. `$tope`: cuántas líneas mostrar (null, todas); el resto se ve en la historia. --}}
@php
    $tope ??= null;
    $lineas = $tope === null ? $pedido->lineas : $pedido->lineas->take($tope);
    $resto = $pedido->lineas->count() - $lineas->count();
@endphp
<ul class="mt-1 space-y-1 text-sm text-gray-700">
    @foreach ($lineas as $linea)
        <li class="flex items-baseline justify-between gap-3">
            <span class="min-w-0">{{ $linea->producto?->nombre ?? 'Producto' }}</span>
            <span class="shrink-0 tabular-nums">{{ $linea->cantidadLegible() }} {{ $linea->cantidadLegible() === '1' ? 'caja' : 'cajas' }}</span>
        </li>
    @endforeach
</ul>
@if ($resto > 0)
    <p class="mt-1 text-xs text-gray-500">Y {{ $resto }} {{ $resto === 1 ? 'producto' : 'productos' }} más en el historial.</p>
@endif
