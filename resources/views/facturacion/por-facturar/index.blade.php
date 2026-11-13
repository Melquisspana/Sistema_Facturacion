{{-- Facturación › «Llegó de la fábrica» (docs/GUIA_PANTALLAS.md): una tarjeta por sala
     (= por pedido) con lo que mandó la fábrica el día elegido, o con lo que sigue sin
     facturar de cualquier día (pastilla «Sin facturar»), la insignia de la OC, cómo va la
     facturación, la hoja para imprimir y «Facturar». Tarjetas también en la computadora:
     cada una es la hoja de una sala.

     Variables: $pedidos (paginado), $oc [pedido_id => propuestas, titulo, aviso, lista],
     $fecha (Y-m-d), $esHoy, $sinFacturar (bool), $facturacion [pedido_id => por_facturar,
     borrador, facturados] y $otrosDiasSinFacturar (int). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Llegó de pedidos</h1>
            {{-- x-secondary-button es solo <button>: un enlace lleva sus clases, como en el resto de Rutas. --}}
            <a href="{{ route('facturacion.index') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a facturación</a>
        </div>
    </x-slot>

    @php
        $dia = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y');
        $pastilla = 'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-medium';
        $elegida = 'bg-indigo-600 text-white';
        $otra = 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50';
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            {{-- Qué mirar: lo del día elegido o todo lo que sigue sin facturar (pastillas, como en Rutas). --}}
            <nav aria-label="Qué mostrar" class="mb-4 flex flex-wrap gap-2">
                <a href="{{ route('facturacion.llego-de-fabrica', $esHoy ? [] : ['fecha' => $fecha]) }}" @unless ($sinFacturar) aria-current="page" @endunless
                   class="{{ $pastilla }} {{ $sinFacturar ? $otra : $elegida }}">Del día</a>
                <a href="{{ route('facturacion.llego-de-fabrica', ['sin_facturar' => 1]) }}" @if ($sinFacturar) aria-current="page" @endif
                   class="{{ $pastilla }} {{ $sinFacturar ? $elegida : $otra }}">Sin facturar</a>
            </nav>

            @if ($otrosDiasSinFacturar > 0)
                <div role="status" class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    Hay {{ $otrosDiasSinFacturar }} {{ $otrosDiasSinFacturar === 1 ? 'pedido' : 'pedidos' }} de otros días sin facturar.
                    <a href="{{ route('facturacion.llego-de-fabrica', ['sin_facturar' => 1]) }}" class="font-semibold underline">Verlos</a>
                </div>
            @endif

            @unless ($sinFacturar)
            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="f-fecha" class="block text-xs font-medium text-gray-500">Día del envío</label>
                    <input id="f-fecha" type="date" name="fecha" value="{{ $fecha }}" class="mt-1 min-h-11 rounded-md border-gray-300 text-sm">
                </div>
                <button class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                @unless ($esHoy)
                    <a href="{{ route('facturacion.llego-de-fabrica') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 underline">Ver hoy</a>
                @endunless
            </form>
            @endunless

            <p class="mb-4 text-sm text-gray-600">
                @if ($sinFacturar)
                    Todo lo que llegó y sigue sin facturar, de cualquier día.
                @else
                    Lo que se despachó el {{ $dia }}{{ $esHoy ? ' (hoy)' : '' }}.
                @endif
                Con la hoja y la OC, tocá «Facturar»: sale el borrador del CCF con todo lo que llegó y ahí bajás lo que la OC no pide.
            </p>

            @if ($pedidos->isEmpty())
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">
                    @if ($sinFacturar)
                        No queda nada sin facturar.
                    @else
                        No llegó nada de pedidos este día.
                        @unless ($esHoy)
                            <a href="{{ route('facturacion.llego-de-fabrica') }}" class="text-indigo-600 underline">Ver hoy</a>
                        @endunless
                    @endif
                </div>
            @else
                <ul class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    @foreach ($pedidos as $pedido)
                        @include('facturacion.por-facturar._tarjeta', ['pedido' => $pedido, 'oc' => $oc[$pedido->id], 'fecha' => $fecha, 'sinFacturar' => $sinFacturar, 'facturacion' => $facturacion[$pedido->id]])
                    @endforeach
                </ul>

                @if ($pedidos->hasPages())
                    <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3">{{ $pedidos->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
