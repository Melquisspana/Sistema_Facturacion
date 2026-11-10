{{-- «Terminar el día» del vendedor (maqueta del 10/11/2026): el resumen de lo que entregó,
     lo que no se pudo, lo cobrado y los quedan recibidos, el PDF del día y, si su viaje
     todavía suma pedidos, la pregunta «¿Mañana seguís en la misma zona?». Solo lectura: el
     dinero se rinde con la liquidación de la oficina.

     Variables: $persona, $resumen (DiaVendedor::resumen), $viaje (el suyo en curso o null) y
     $sumando (si todavía suma pedidos). --}}
@extends('layouts.vendedor')

@section('titulo', 'Terminar el día')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $cuadro = 'rounded-xl bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-ink-700 dark:ring-ink-600';
        $dinero = fn (int $centavos) => '$'.number_format($centavos / 100, 2);
    @endphp

    <h1 class="text-3xl font-bold">Terminar el día</h1>
    <p class="mt-1 {{ $gris }}">{{ ucfirst(\App\Support\HoraNegocio::hoy()->locale('es')->dayName) }} {{ \App\Support\HoraNegocio::hoy()->format('d/m/Y') }}</p>

    <dl class="mt-5 grid grid-cols-2 gap-3">
        <div class="{{ $cuadro }}"><dt class="{{ $gris }}">Entregas</dt><dd class="text-2xl font-bold tabular-nums">{{ $resumen['entregadas']->count() }}</dd></div>
        <div class="{{ $cuadro }}"><dt class="{{ $gris }}">No se pudo</dt><dd class="text-2xl font-bold tabular-nums">{{ $resumen['no_entregadas']->count() }}</dd></div>
        <div class="{{ $cuadro }}"><dt class="{{ $gris }}">Efectivo</dt><dd class="text-2xl font-bold tabular-nums">{{ $dinero($resumen['efectivo']) }}</dd></div>
        <div class="{{ $cuadro }}"><dt class="{{ $gris }}">Cheque / transf.</dt><dd class="text-2xl font-bold tabular-nums">{{ $dinero($resumen['cheque'] + $resumen['transferencia']) }}</dd></div>
    </dl>

    <div class="mt-3 {{ $caja }}">
        <h2 class="font-semibold">Quedan recibidos</h2>
        <p class="{{ $gris }}">{{ $resumen['quedan']['cantidad'] === 1 ? '1 factura' : $resumen['quedan']['cantidad'].' facturas' }}, {{ $dinero($resumen['quedan']['monto']) }}</p>
        <ul class="mt-1 space-y-1 text-sm">
            @foreach ($resumen['entregadas']->filter(fn ($e) => $e->quedan_numero || $e->quedan_fecha_pago) as $entrega)
                <li>{{ $entrega->sala?->nombre }}{{ $entrega->quedan_numero ? ' · N.º '.$entrega->quedan_numero : '' }}{{ $entrega->quedan_fecha_pago ? ' · paga el '.$entrega->quedan_fecha_pago->format('d/m/Y') : '' }}</li>
            @endforeach
        </ul>
        @if ($resumen['portal']['cantidad'] > 0)
            <p class="mt-1 {{ $gris }}">Por el portal del supermercado: {{ $resumen['portal']['cantidad'] === 1 ? '1 factura' : $resumen['portal']['cantidad'].' facturas' }}, {{ $dinero($resumen['portal']['monto']) }}</p>
        @endif
    </div>

    @if ($resumen['no_entregadas']->isNotEmpty())
        <div class="mt-3 {{ $caja }}">
            <h2 class="font-semibold">No se pudo entregar</h2>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($resumen['no_entregadas'] as $entrega)
                    <li>{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }} · {{ $entrega->motivo_no_entrega?->label() }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Si mañana no sigue en la zona, el viaje deja de sumar pedidos. --}}
    @if ($viaje && $sumando)
        <form method="POST" action="{{ route('rutas.mi-salida.terminar-dia', $viaje) }}" class="mt-4 {{ $caja }}">
            @csrf
            <p class="rounded-xl bg-blue-50 p-3 font-semibold text-blue-700">¿Mañana seguís en la misma zona?</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button name="sigue" value="1" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Sí, sigo</button>
                <button name="sigue" value="0" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">No, vuelvo</button>
            </div>
            <p class="mt-2 {{ $gris }}">Si volvés, el viaje deja de sumar pedidos y se cierra solo cuando todo esté entregado.</p>
        </form>
    @endif

    <div class="mt-4 grid gap-3">
        <a href="{{ route('rutas.mi-salida.dia.pdf') }}" class="inline-flex min-h-12 w-full items-center justify-center rounded-2xl border border-gray-300 bg-white px-4 py-3 text-lg font-semibold text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">Ver PDF del día</a>
        <a href="{{ route('rutas.mi-salida.index') }}" class="inline-flex min-h-11 items-center justify-center text-sm text-indigo-600 underline dark:text-indigo-300">Volver al inicio</a>
    </div>
    <p class="mt-3 {{ $gris }}">El PDF lleva lo entregado, lo que no se pudo y lo cobrado. Con él entregás a la oficina el efectivo, los cheques y los quedan, y lo firman los dos.</p>
@endsection
