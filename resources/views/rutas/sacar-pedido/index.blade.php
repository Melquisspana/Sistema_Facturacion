{{-- «Sacar pedido» (maqueta aprobada por Melqui el 10/11/2026): el vendedor busca cualquier
     sala, sin salida armada y sin que sea de una ruta, y saca el pedido. El pedido se suma solo
     a su viaje. La búsqueda es un formulario común (anda sin JavaScript) con la misma consulta
     que el buscador de salas de la oficina; cada sala dice hace cuánto fue su última visita y si
     «le toca» según su ritmo real (RitmoRutas::deSalas, el mismo del tablero). Abajo, sus pedidos de hoy para corregir o anular. --}}
@extends('layouts.vendedor')

@section('titulo', 'Sacar pedido')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $campo = 'min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    @endphp

    <h1 class="text-3xl font-bold">Sacar pedido</h1>
    @if ($viaje)
        <p class="mt-1 {{ $gris }}">Va a tu viaje: {{ $viaje->nombreViaje() }}</p>
    @endif

    @if (! $persona)
        <div class="mt-5 {{ $caja }}">
            <p class="font-semibold">Tu usuario no es vendedor</p>
            <p class="{{ $gris }}">Por eso no podés sacar pedidos. Podés mirar lo que falta entregar. Si salís a vender, pedile a la oficina que revise tu ficha en Personal.</p>
            <a href="{{ route('rutas.por-entregar.index') }}" class="{{ $secundario }} mt-3 w-full">Ver «Por entregar»</a>
        </div>
    @else
        <form method="GET" action="{{ route('rutas.sacar-pedido.index') }}" class="mt-5 {{ $caja }}" role="search">
            <label for="buscar-sala" class="block font-semibold">Buscar sala o tienda</label>
            <div class="mt-2 flex gap-2">
                <input id="buscar-sala" name="q" type="search" value="{{ $texto }}" autocomplete="off" maxlength="100"
                       placeholder="Nombre, código, cliente o pueblo…" class="{{ $campo }}">
                <button class="{{ $secundario }} shrink-0">Buscar</button>
            </div>
            @if ($texto !== '' && mb_strlen($texto) < 2)
                <p class="mt-2 {{ $gris }}">Escribí al menos 2 letras.</p>
            @endif
        </form>

        @if ($salas !== null)
            <section class="mt-4" aria-labelledby="resultados-titulo">
                <h2 id="resultados-titulo" class="sr-only">Salas encontradas</h2>
                @if ($salas->isEmpty())
                    <p class="{{ $caja }}">Ninguna sala coincide con «{{ $texto }}».</p>
                @else
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:divide-ink-700 dark:bg-ink-800 dark:ring-ink-600">
                        @foreach ($salas as $sala)
                            <li>
                                <a href="{{ route('rutas.sacar-pedido.create', $sala) }}" class="flex min-h-11 items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
                                    <span class="min-w-0">
                                        <span class="block font-semibold">{{ $sala->nombre }}</span>
                                        <span class="block {{ $gris }}">{{ collect([$sala->codigo, $sala->distrito?->nombre, $sala->cliente?->nombre])->filter()->implode(' · ') }}</span>
                                        @if (($ritmos[$sala->id]['dias'] ?? null) !== null)
                                            <span class="block {{ $gris }}">{{ match ($ritmos[$sala->id]['dias']) { 0 => 'Última visita hoy', 1 => 'Última visita ayer', default => 'Última visita hace '.$ritmos[$sala->id]['dias'].' días' } }}</span>
                                        @endif
                                    </span>
                                    @if ($ritmos[$sala->id]['leToca'] ?? false)
                                        <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Le toca</span>
                                    @endif
                                    @if ($sacadas->has($sala->id))
                                        <span class="inline-flex shrink-0 items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">Pedido sacado</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- Tienda nueva: si no está en la lista, solo el nombre. La oficina la da de alta al facturar. --}}
        <form method="GET" action="{{ route('rutas.sacar-pedido.tienda-nueva.create') }}"
              class="mt-4 rounded-2xl border border-dashed border-indigo-300 bg-white p-4 dark:border-ink-600 dark:bg-ink-800">
            <label for="tienda-nueva" class="block font-semibold">Tienda nueva</label>
            <p class="mt-1 {{ $gris }}">¿No está en la lista? Escribí solo el nombre. Sus datos se cargan en la oficina, al facturar.</p>
            <div class="mt-2 flex gap-2">
                <input id="tienda-nueva" name="nombre" type="text" value="{{ $texto }}" required minlength="3" maxlength="100" autocomplete="off"
                       placeholder="Nombre de la tienda" class="{{ $campo }}">
                <button class="{{ $secundario }} shrink-0">Seguir</button>
            </div>
        </form>

        <section class="mt-6" aria-labelledby="hoy-titulo">
            <h2 id="hoy-titulo" class="text-xl font-semibold">Tus pedidos de hoy</h2>
            @if ($pedidosHoy->isEmpty())
                <p class="mt-2 {{ $gris }}">Todavía no sacaste pedidos hoy.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($pedidosHoy as $pedido)
                        <li class="{{ $caja }}">
                            <p class="font-semibold">{{ $pedido->nombreSala() }}</p>
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($pedido->lineas as $linea)
                                    <li><span class="font-semibold tabular-nums">{{ $linea->cantidadLegible() }}</span> × {{ $linea->producto?->nombre }}</li>
                                @endforeach
                            </ul>
                            {{-- La forma de la orden de compra solo avisa; se guarda igual. --}}
                            @foreach ($pedido->avisosOrdenCompra() as $aviso)
                                <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ $aviso }}</p>
                            @endforeach
                            @can('corregir', $pedido)
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <a href="{{ route('rutas.sacar-pedido.edit', $pedido) }}" class="{{ $secundario }}">Corregir</a>
                                    <form method="POST" action="{{ route('rutas.sacar-pedido.anular', $pedido) }}"
                                          onsubmit="return confirm('¿Anular el pedido de {{ addslashes($pedido->nombreSala()) }}? Después lo podés sacar de nuevo.');">
                                        @csrf @method('PATCH')
                                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Anular</button>
                                    </form>
                                </div>
                            @else
                                {{-- Por qué ya no lo corrige: sacó el de la sala siguiente o la fábrica lo empezó. --}}
                                <p class="mt-2 {{ $gris }}">{{ \App\Services\Rutas\PedidosSala::porQueNoSeCorrige($pedido) ?? $pedido->estado->label() }}</p>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
@endsection
