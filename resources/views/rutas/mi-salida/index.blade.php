{{-- Inicio del vendedor (maqueta aprobada por Melqui el 10/11/2026): su viaje, que se arma
     solo con el primer pedido, los dos botones grandes (con lo que falta entregar) y lo de hoy:
     pedidos, entregas y cobrado. «Terminar el día» abre el resumen, el PDF y la pregunta de si
     mañana sigue en la misma zona. Las salidas que arma la oficina siguen abajo, como antes. --}}
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
            <a href="{{ route('rutas.por-entregar.index') }}" class="{{ $grande }} gap-2 border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">
                Por entregar
                @if ($porEntregar > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-2.5 text-sm font-semibold text-white">{{ $porEntregar }}</span>
                @endif
            </a>
        </div>

        {{-- Lo de hoy, en cualquier viaje o salida. «Terminar el día» abre el resumen, el PDF y la pregunta. --}}
        <div class="mt-4 {{ $caja }}">
            <h2 class="text-lg font-semibold">Hoy</h2>
            <dl class="mt-1 divide-y divide-gray-100 dark:divide-ink-700">
                <div class="flex justify-between gap-3 py-1.5"><dt>Pedidos sacados</dt><dd class="font-semibold tabular-nums">{{ $hoy['pedidos'] }}</dd></div>
                <div class="flex justify-between gap-3 py-1.5"><dt>Entregas marcadas</dt><dd class="font-semibold tabular-nums">{{ $hoy['entregadas']->count() }}</dd></div>
                <div class="flex justify-between gap-3 py-1.5"><dt>Cobrado</dt><dd class="font-semibold tabular-nums">${{ number_format($hoy['cobrado'] / 100, 2) }}</dd></div>
            </dl>
            <a href="{{ route('rutas.mi-salida.dia') }}" class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Terminar el día</a>
        </div>

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
