{{-- Facturación › «Llegó de la fábrica» (docs/GUIA_PANTALLAS.md): una tarjeta por sala
     (= por pedido) con lo que mandó la fábrica el día elegido, la insignia de la OC y la
     hoja para imprimir. Tarjetas también en la computadora: cada una es la hoja de una sala.
     Solo lectura: todavía no factura.

     Variables: $pedidos (paginado), $oc [pedido_id => propuestas, del_correo, lista],
     $fecha (Y-m-d), $esHoy. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Llegó de la fábrica</h1>
            {{-- x-secondary-button es solo <button>: un enlace lleva sus clases, como en el resto de Rutas. --}}
            <a href="{{ route('facturacion.index') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a facturación</a>
        </div>
    </x-slot>

    @php $dia = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y'); @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

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

            <p class="mb-4 text-sm text-gray-600">
                Lo que mandó la fábrica el {{ $dia }}{{ $esHoy ? ' (hoy)' : '' }}.
                Con la hoja y la OC se hace el CCF desde «Nuevo CCF».
            </p>

            @if ($pedidos->isEmpty())
                <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-gray-500">
                    No llegó nada de la fábrica este día.
                    @unless ($esHoy)
                        <a href="{{ route('facturacion.llego-de-fabrica') }}" class="text-indigo-600 underline">Ver hoy</a>
                    @endunless
                </div>
            @else
                <ul class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    @foreach ($pedidos as $pedido)
                        @include('facturacion.por-facturar._tarjeta', ['pedido' => $pedido, 'oc' => $oc[$pedido->id]])
                    @endforeach
                </ul>

                @if ($pedidos->hasPages())
                    <div class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3">{{ $pedidos->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
