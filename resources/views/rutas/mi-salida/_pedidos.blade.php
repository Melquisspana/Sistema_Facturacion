{{-- Los pedidos de esta salida (desde el 10/11/2026, los del viaje: se suman solos al sacarlos).
     Sacar uno nuevo se hace desde «Sacar pedido», en cualquier sala; acá se ve lo sacado y, para
     quien lo sacó, Corregir y Anular: hasta que saque el pedido de la sala siguiente o la
     fábrica lo empiece (PedidoSalaPolicy::corregir). Después lo corrige la oficina. --}}
@php
    $cajaPedido = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $gris = 'text-sm text-gray-600 dark:text-paper-300';
    $salasConPedido = $pedidosPorSala->pluck('cliente_sucursal_id')->unique()->count();
@endphp

<section class="mt-6" aria-labelledby="pedidos-titulo">
    <h2 id="pedidos-titulo" class="text-xl font-semibold">Pedidos</h2>
    <p class="mt-1 {{ $gris }}">{{ $salasConPedido === 1 ? '1 sala con pedido.' : $salasConPedido.' salas con pedido.' }}</p>
    @can('create', \App\Models\PedidoSala::class)
        <a href="{{ route('rutas.sacar-pedido.index') }}"
           class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Sacar pedido</a>
    @endcan

    @if ($pedidosPorSala->isNotEmpty())
        <ul class="mt-3 space-y-3">
            @foreach ($pedidosPorSala as $pedido)
                <li id="sala-{{ $pedido->cliente_sucursal_id }}" class="{{ $cajaPedido }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-medium">{{ $pedido->nombreSala() }}</p>
                        <span class="inline-flex shrink-0 items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">Pedido sacado</span>
                    </div>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($pedido->lineas as $linea)
                            <li><span class="font-semibold tabular-nums">{{ $linea->cantidadLegible() }}</span> × {{ $linea->producto?->nombre }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 {{ $gris }}">{{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->numero_orden_compra ? 'OC '.$pedido->numero_orden_compra : 'Sin OC' }}</p>
                    @foreach ($pedido->avisosOrdenCompra() as $aviso)
                        <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ $aviso }}</p>
                    @endforeach
                    @if ($pedido->notas)
                        <p class="mt-1 whitespace-pre-line {{ $gris }}">{{ $pedido->notas }}</p>
                    @endif
                    @can('corregir', $pedido)
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <a href="{{ route('rutas.sacar-pedido.edit', $pedido) }}"
                               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Corregir</a>
                            <form method="POST" action="{{ route('rutas.sacar-pedido.anular', $pedido) }}"
                                  onsubmit="return confirm('¿Anular el pedido de {{ addslashes($pedido->nombreSala()) }}? Después lo podés sacar de nuevo.');">
                                @csrf @method('PATCH')
                                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Anular</button>
                            </form>
                        </div>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>
