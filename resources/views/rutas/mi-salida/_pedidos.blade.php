{{-- «Sacar pedido» en «Mi salida» (docs/PLAN_TABLERO_RUTAS.md, PR 9 y 10): una tarjeta por
     sala de la salida, las atrasadas primero. Sin pedido, el botón «Sacar pedido»; con pedido,
     «Pedido sacado» con su resumen y, para quien lo sacó y el mismo día, Corregir y Anular.
     Mismas cajas e insignias que «Tu próxima salida». --}}
@php
    $S = \App\Services\Rutas\Ritmo::class;
    $insignias = [
        $S::ATRASADA => ['Atrasada', 'bg-red-100 text-red-700'],
        $S::PRONTO => ['Toca pronto', 'bg-amber-100 text-amber-800'],
    ];
    $cajaPedido = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $gris = 'text-sm text-gray-600 dark:text-paper-300';
    $sacados = $salasPedido->filter(fn ($f) => $pedidosPorSala->has($f['sala']->id))->count();
@endphp

<section class="mt-6" aria-labelledby="pedidos-titulo">
    <h2 id="pedidos-titulo" class="text-xl font-semibold">Sacar pedido</h2>
    <p class="mt-1 {{ $gris }}">{{ $sacados }} de {{ $salasPedido->count() }} salas con pedido. Las atrasadas primero.</p>

    @if ($salasPedido->isEmpty())
        <p class="mt-3 {{ $cajaPedido }}">Esta ruta todavía no tiene salas. Pedile a la oficina que las asigne.</p>
    @else
        <ul class="mt-3 space-y-3">
            @foreach ($salasPedido as $fila)
                @php
                    $sala = $fila['sala'];
                    $pedido = $pedidosPorSala->get($sala->id);
                    $insignia = $insignias[$fila['estado']] ?? null;
                @endphp
                <li id="sala-{{ $sala->id }}" class="{{ $cajaPedido }}">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-medium">{{ $sala->nombre }}</p>
                        @if ($pedido)
                            <span class="inline-flex shrink-0 items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">Pedido sacado</span>
                        @elseif ($insignia)
                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $insignia[1] }}">{{ $insignia[0] }}</span>
                        @endif
                    </div>

                    @if ($pedido)
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($pedido->lineas as $linea)
                                <li><span class="font-semibold tabular-nums">{{ $linea->cantidadLegible() }}</span> × {{ $linea->producto?->nombre }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 {{ $gris }}">{{ $pedido->numero_orden_compra ? 'OC '.$pedido->numero_orden_compra : 'Sin OC' }}</p>
                        @foreach ($pedido->avisosOrdenCompra() as $aviso)
                            <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ $aviso }}</p>
                        @endforeach
                        @if ($pedido->notas)
                            <p class="mt-1 whitespace-pre-line {{ $gris }}">{{ $pedido->notas }}</p>
                        @endif
                        @can('corregir', $pedido)
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <a href="{{ route('rutas.mi-salida.pedidos.edit', [$salida, $pedido]) }}"
                                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Corregir</a>
                                <form method="POST" action="{{ route('rutas.mi-salida.pedidos.anular', [$salida, $pedido]) }}"
                                      onsubmit="return confirm('¿Anular el pedido de {{ addslashes($sala->nombre) }}? Después lo podés sacar de nuevo.');">
                                    @csrf @method('PATCH')
                                    <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Anular</button>
                                </form>
                            </div>
                        @endcan
                    @else
                        <a href="{{ route('rutas.mi-salida.pedidos.create', [$salida, $sala]) }}"
                           class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Sacar pedido</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
