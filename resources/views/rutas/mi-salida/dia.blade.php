{{-- «Terminar el día» del vendedor (maqueta del 10/11/2026): el resumen de lo que entregó,
     lo que no se pudo, lo cobrado y los quedan recibidos, el PDF del día y, si su viaje
     todavía suma pedidos, la pregunta «¿Mañana seguís en la misma zona?». Los gastos del día
     (gasolina, parqueo, con foto) se anotan acá, en el viaje, y salen en el PDF. El dinero se
     entrega con ese PDF firmado: en el sistema no se liquida nada.

     Con `?fecha=` muestra un día anterior (su resumen y su PDF), sin la pregunta ni el
     formulario de gastos. Abajo, el PDF de cada día anterior con movimiento: el viaje dura
     varios días y cada día se firma aparte.

     Variables: $persona, $fecha (AAAA-MM-DD), $esHoy, $resumen (DiaVendedor::resumen), $viaje
     (el suyo en curso o null), $sumando (si todavía suma pedidos), $puedeAnotarGastos y
     $diasAnteriores (fechas con movimiento antes de hoy). --}}
@extends('layouts.vendedor')

@section('titulo', $esHoy ? 'Terminar el día' : 'Resumen del día')

@section('content')
    @php
        $caja = 'rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $gris = 'text-sm text-gray-600 dark:text-paper-300';
        $cuadro = 'rounded-xl bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-ink-700 dark:ring-ink-600';
        $dinero = fn (int $centavos) => '$'.number_format($centavos / 100, 2);
        $diaLegible = fn (string $d) => ucfirst(\Illuminate\Support\Carbon::parse($d)->locale('es')->dayName).' '.\Illuminate\Support\Carbon::parse($d)->format('d/m/Y');
    @endphp

    <h1 class="text-3xl font-bold">{{ $esHoy ? 'Terminar el día' : 'Resumen del día' }}</h1>
    <p class="mt-1 {{ $gris }}">{{ $diaLegible($fecha) }}</p>
    @unless ($esHoy)
        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 ring-1 ring-amber-200">Es un día anterior: solo se mira y se saca su PDF.
            <a href="{{ route('rutas.mi-salida.dia') }}" class="font-medium underline">Volver a hoy</a></p>
    @endunless

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

    {{-- Gastos del día: se anotan en el viaje (hace falta uno en curso) y salen en el PDF. --}}
    @if ($puedeAnotarGastos)
        @include('rutas.salidas._gastos', ['salida' => $viaje, 'modoVendedor' => true])
    @elseif ($resumen['gastos']->isNotEmpty())
        <div class="mt-3 {{ $caja }}">
            <h2 class="font-semibold">{{ $esHoy ? 'Gastos de hoy' : 'Gastos del día' }} · {{ $dinero($resumen['gastos_total']) }}</h2>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($resumen['gastos'] as $gasto)
                    <li>{{ $gasto->concepto->label() }} · ${{ number_format((float) $gasto->monto, 2) }}{{ $gasto->detalle ? ' · '.$gasto->detalle : '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <p class="mt-3 rounded-xl bg-gray-50 p-3 text-sm ring-1 ring-gray-200 dark:bg-ink-700 dark:ring-ink-600">
        Efectivo a entregar: <span class="font-bold tabular-nums">{{ $dinero($resumen['a_entregar']) }}</span>
        <span class="{{ $gris }}">({{ $dinero($resumen['efectivo']) }} cobrado menos {{ $dinero($resumen['gastos_total']) }} de gastos)</span>
    </p>

    {{-- Si mañana no sigue en la zona, el viaje deja de sumar pedidos. --}}
    @if ($esHoy && $viaje && $sumando)
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
        <a href="{{ route('rutas.mi-salida.dia.pdf', $esHoy ? [] : ['fecha' => $fecha]) }}" class="inline-flex min-h-12 w-full items-center justify-center rounded-2xl border border-gray-300 bg-white px-4 py-3 text-lg font-semibold text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">Ver PDF del día</a>
    </div>
    <p class="mt-3 {{ $gris }}">El PDF lleva lo entregado, lo que no se pudo, lo cobrado y tus gastos. Con él entregás a la oficina el efectivo, los cheques y los quedan, y lo firman los dos.</p>

    {{-- El viaje dura varios días: cada día con movimiento tiene su PDF, para firmarlo aunque sea después. --}}
    @if ($diasAnteriores !== [])
        <section aria-labelledby="dias-anteriores" class="mt-4 {{ $caja }}">
            <h2 id="dias-anteriores" class="font-semibold">{{ $viaje ? 'Días anteriores de este viaje' : 'Días anteriores' }}</h2>
            <p class="{{ $gris }}">El PDF de cada día en que entregaste, cobraste o gastaste.</p>
            <ul class="mt-1 divide-y divide-gray-100 dark:divide-ink-700">
                @foreach (array_reverse($diasAnteriores) as $anterior)
                    <li>
                        <a href="{{ route('rutas.mi-salida.dia.pdf', ['fecha' => $anterior]) }}" class="flex min-h-11 items-center justify-between gap-3 py-2 text-indigo-600 dark:text-indigo-300">
                            <span>{{ $diaLegible($anterior) }}</span>
                            <span class="text-sm font-medium underline">Ver PDF</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <a href="{{ route('rutas.mi-salida.index') }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center text-sm text-indigo-600 underline dark:text-indigo-300">Volver al inicio</a>
@endsection
