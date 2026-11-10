{{-- Inicio del vendedor (maqueta aprobada por Melqui el 10/11/2026): su viaje, que se arma
     solo con el primer pedido, y los dos botones grandes. Al final del día contesta si mañana
     sigue en la misma zona. Las salidas que arma la oficina siguen abajo, como antes. --}}
@extends('layouts.vendedor')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-5 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $grande = 'inline-flex min-h-12 w-full items-center justify-center rounded-2xl px-4 py-3 text-lg font-semibold';
    @endphp

    <h1 class="text-3xl font-bold">Mi salida</h1>
    <p class="mt-1 {{ $gris }}">{{ ucfirst(\App\Support\HoraNegocio::hoy()->locale('es')->dayName) }} {{ \App\Support\HoraNegocio::hoy()->format('d/m/Y') }}</p>

    @if (! $persona)
        <p class="mt-6 {{ $caja }}">Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.</p>
    @else
        @if ($viaje)
            <a href="{{ route('rutas.mi-salida.show', $viaje) }}" class="mt-5 block min-h-11 rounded-2xl bg-indigo-50 p-5 text-indigo-800 ring-1 ring-indigo-200 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-600">
                <span class="block text-sm">Tu viaje</span>
                <span class="block text-xl font-semibold">{{ $viaje->nombreViaje() }}</span>
                <span class="block text-sm">Día {{ $dia }} · {{ $salasConPedido === 1 ? '1 sala con pedido' : $salasConPedido.' salas con pedido' }}</span>
                @unless ($sumando)
                    <span class="mt-2 block text-sm font-medium">Ya no suma pedidos: queda abierto para entregar. Tu próximo pedido abre otro viaje.</span>
                @endunless
            </a>
        @else
            <div class="mt-5 {{ $caja }}">
                @if ($salidas->isEmpty())
                    <p class="font-semibold">No tenés salidas en camino</p>
                @endif
                <p class="{{ $gris }}">Tu viaje se abre solo con el primer pedido que saqués.</p>
            </div>
        @endif

        <div class="mt-4 grid gap-3">
            @can('rutas.pedidos.registrar')
                <a href="{{ route('rutas.sacar-pedido.index') }}" class="{{ $grande }} bg-indigo-600 text-white hover:bg-indigo-700">Sacar pedido</a>
            @endcan
            <a href="{{ route('rutas.por-entregar.index') }}" class="{{ $grande }} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">Por entregar</a>
        </div>

        @if ($viaje)
            <div class="mt-4 {{ $caja }}">
                <h2 class="text-lg font-semibold">Hoy</h2>
                <p class="mt-1 flex justify-between gap-3"><span>Pedidos sacados</span><strong class="tabular-nums">{{ $pedidosHoy }}</strong></p>
            </div>
        @endif

        {{-- «Terminar el día»: si mañana no sigue en la zona, el viaje deja de sumar pedidos. --}}
        @if ($viaje && $sumando)
            <form method="POST" action="{{ route('rutas.mi-salida.terminar-dia', $viaje) }}" class="mt-4 {{ $caja }}">
                @csrf
                <h2 class="text-lg font-semibold">Terminar el día</h2>
                <p class="mt-2 rounded-xl bg-blue-50 p-3 font-semibold text-blue-700">¿Mañana seguís en la misma zona?</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button name="sigue" value="1" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Sí, sigo</button>
                    <button name="sigue" value="0" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">No, vuelvo</button>
                </div>
                <p class="mt-2 {{ $gris }}">Si volvés, el viaje deja de sumar pedidos y se cierra solo cuando todo esté entregado.</p>
            </form>
        @endif

        @if ($salidas->isNotEmpty())
            <h2 class="mt-6 text-xl font-semibold">Salidas en camino</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($salidas as $salida)
                    <li>
                        <a href="{{ route('rutas.mi-salida.show', $salida) }}" class="block min-h-11 {{ $caja }}">
                            <span class="block text-xl font-semibold">{{ $salida->titulo() }}</span>
                            <span class="{{ $gris }}">{{ $salida->periodoLegible() }} · {{ $salida->proposito->descripcion() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($proximas->isNotEmpty())
            <h2 class="mt-6 text-xl font-semibold">Próximas salidas</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($proximas as $salida)
                    <li>
                        <a href="{{ route('rutas.mi-salida.show', $salida) }}" class="block min-h-11 {{ $caja }}">
                            <span class="block text-xl font-semibold">{{ $salida->ruta->nombre }}</span>
                            <span class="{{ $gris }}">{{ ucfirst($salida->fecha_inicio->copy()->locale('es')->dayName) }} {{ $salida->fecha_inicio->format('d/m/Y') }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
@endsection
