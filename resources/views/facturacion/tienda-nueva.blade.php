{{-- Tienda nueva al facturar (Melqui, 10/11/2026): el vendedor anotó solo el nombre. La
     oficina la enlaza a su sala antes de facturar: busca si ya existe y la enlaza, o la da de
     alta con el formulario de Clientes, que vuelve enlazando. Sin sala no se factura.

     Variables: $pedido (sacadoPor, ruta), $texto (lo buscado; al entrar, el nombre que escribió
     el vendedor), $salas (las que coinciden, con cliente y distrito) y $puedeDarDeAlta
     (clientes.gestionar, o clientes.alta para esta tienda nueva). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Tienda nueva: {{ $pedido->sala_nueva_nombre }}</h1>
            <a href="{{ route('facturacion.llego-de-fabrica', ['sin_facturar' => 1]) }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a Llegó de la fábrica</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $dt = 'text-xs font-medium text-gray-500';
        $dd = 'mt-0.5 text-sm text-gray-800';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $principal = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700';
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <section class="{{ $caja }}">
                <p class="text-sm text-amber-800">{{ \App\Services\Rutas\FacturarPedido::SIN_SALA }}</p>
                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="{{ $dt }}">Nombre que escribió el vendedor</dt><dd class="{{ $dd }}">{{ $pedido->sala_nueva_nombre }}</dd></div>
                    <div><dt class="{{ $dt }}">Pedido</dt><dd class="{{ $dd }}">N.º {{ $pedido->id }} · {{ $pedido->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Lo sacó</dt><dd class="{{ $dd }}">{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Zona</dt><dd class="{{ $dd }}">{{ $pedido->zonaNombre() }}</dd></div>
                </dl>
            </section>

            {{-- 1. Si no está en Clientes: el alta con el formulario de siempre, que vuelve enlazando. --}}
            <section class="{{ $caja }}" aria-labelledby="alta-titulo">
                <h2 id="alta-titulo" class="text-base font-semibold text-gray-800">Si todavía no está en Clientes</h2>
                @if ($puedeDarDeAlta)
                    <p class="mt-1 text-sm text-gray-600">Cargá el cliente y después su sala. Al guardar la sala, queda enlazada a este pedido y volvés a facturar.</p>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <a href="{{ route('clientes.create', ['pedido_sala' => $pedido->id]) }}" class="{{ $principal }}">Dar de alta la tienda</a>
                    </div>
                @else
                    <p class="mt-1 text-sm text-gray-600">Pedile a quien maneja Clientes que la dé de alta con estos datos. Después buscala abajo y enlazala.</p>
                @endif
            </section>

            {{-- 2. Si ya existe: buscarla y enlazarla. --}}
            <section class="{{ $caja }}" aria-labelledby="buscar-titulo">
                <h2 id="buscar-titulo" class="text-base font-semibold text-gray-800">Si ya está en Clientes</h2>
                <form method="GET" action="{{ route('facturacion.pedidos.tienda', $pedido) }}" class="mt-2" role="search">
                    <label for="buscar-sala" class="block text-sm font-medium text-gray-700">Buscar sala</label>
                    <div class="mt-1 flex gap-2">
                        <input id="buscar-sala" name="q" type="search" value="{{ $texto }}" maxlength="100" autocomplete="off"
                               placeholder="Nombre, código, cliente o pueblo" class="min-h-11 w-full rounded-md border-gray-300 text-sm">
                        <button class="{{ $secundario }} shrink-0">Buscar</button>
                    </div>
                </form>

                @if ($salas->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">{{ mb_strlen($texto) >= 2 ? 'Ninguna sala coincide con «'.$texto.'».' : 'Escribí al menos 2 letras.' }}</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                        @foreach ($salas as $sala)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-gray-800">{{ $sala->nombre }}</span>
                                    <span class="block text-xs text-gray-500">{{ collect([$sala->codigo, $sala->distrito?->nombre, $sala->cliente?->nombre])->filter()->implode(' · ') }}</span>
                                </span>
                                <form method="POST" action="{{ route('facturacion.pedidos.tienda.enlazar', $pedido) }}"
                                      onsubmit="return confirm('¿Enlazar el pedido de {{ addslashes($pedido->sala_nueva_nombre) }} a la sala {{ addslashes($sala->nombre) }}? Después no se cambia.');">
                                    @csrf
                                    <input type="hidden" name="cliente_sucursal_id" value="{{ $sala->id }}">
                                    <button class="{{ $secundario }}">Enlazar</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
