@extends('layouts.vendedor')

@section('content')
    <h1 class="text-3xl font-bold">{{ $salida->ruta->nombre }}</h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">{{ $salida->periodoLegible() }}</p>
    @php
        $companeros = $salida->participantes->filter(fn ($p) => $p->rutas_personal_id !== $persona->id)->pluck('personal.nombre')->filter()->join(', ', ' y ');
    @endphp
    @if ($companeros)
        <p class="mt-2 text-base">Vas con {{ $companeros }}</p>
    @endif
    <div class="mt-5 rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
        <p class="text-base font-semibold">{{ $resumen['entregados'] }} de {{ $resumen['total'] }} entregados · {{ $resumen['no_entregados'] }} no entregado{{ $resumen['no_entregados'] === 1 ? '' : 's' }}</p>
        <div role="progressbar" aria-label="CCF entregados" aria-valuemin="0" aria-valuemax="{{ $resumen['total'] }}" aria-valuenow="{{ $resumen['entregados'] }}"
             class="mt-3 h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-ink-700">
            <div class="h-3 rounded-full bg-green-500" style="width: {{ $resumen['total'] ? round($resumen['entregados'] * 100 / $resumen['total']) : 0 }}%"></div>
        </div>
    </div>
    @include('rutas.salidas._entregas')
@endsection
