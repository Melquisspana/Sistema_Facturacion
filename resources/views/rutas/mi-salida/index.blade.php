@extends('layouts.vendedor')

@section('content')
    <h1 class="text-3xl font-bold">Mi salida</h1>
    @if (! $persona)
        <p class="mt-6 rounded-2xl bg-white p-5 dark:bg-ink-800">Tu usuario no está enlazado a una persona de Rutas. Pedile a la oficina que lo enlace.</p>
    @else
        <ul class="mt-6 space-y-3">
            @forelse ($salidas as $salida)
                <li>
                    <a href="{{ route('rutas.mi-salida.show', $salida) }}" class="block min-h-11 rounded-2xl bg-white p-5 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                        <span class="block text-xl font-semibold">{{ $salida->ruta->nombre }}</span>
                        <span class="text-sm text-gray-600 dark:text-paper-300">{{ $salida->periodoLegible() }}</span>
                    </a>
                </li>
            @empty
                <li class="rounded-2xl bg-white p-5 dark:bg-ink-800">No tenés salidas en camino</li>
            @endforelse
        </ul>
    @endif
@endsection
