{{-- La oficina anula un pedido de sala (Melqui, 13/11/2026): con motivo y la confirmación del
     navegador (docs/GUIA_PANTALLAS.md, «Confirmaciones», nivel 2: anula algo interno). Solo
     sin envíos vigentes; la regla está en PedidosOficina. Botones con las clases de la guía
     escritas a mano, como el detalle del pedido.

     Variables: $pedido (líneas, envíos y sala). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Anular pedido de {{ $pedido->nombreSala() }}</h1>
            <a href="{{ route('rutas.pedidos.show', $pedido) }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al pedido</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $dt = 'text-xs font-medium text-gray-500';
        $dd = 'mt-0.5 text-sm text-gray-800';
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <section class="{{ $caja }}">
                <p class="text-sm text-gray-700">El pedido queda anulado con sus productos, para consultarlo. Pedidos ya no lo ve y no se factura. No se puede deshacer.</p>
                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="{{ $dt }}">Sala</dt><dd class="{{ $dd }}">{{ $pedido->nombreSala() }}</dd></div>
                    <div><dt class="{{ $dt }}">Fecha</dt><dd class="{{ $dd }}">{{ $pedido->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="{{ $dt }}">Lo sacó</dt><dd class="{{ $dd }}">{{ $pedido->sacadoPor?->nombre ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Productos</dt><dd class="{{ $dd }}">{{ $pedido->lineas->map(fn ($l) => $l->producto?->nombre.' '.$l->cantidadLegible())->implode(' · ') }}</dd></div>
                </dl>
            </section>

            <form method="POST" action="{{ route('rutas.pedidos.anular.guardar', $pedido) }}" class="{{ $caja }}"
                  onsubmit="return confirm(@js('¿Anular el pedido de '.$pedido->nombreSala().'? No se puede deshacer.'));">
                @csrf @method('PATCH')
                <input type="hidden" name="version" value="{{ \App\Services\Rutas\PedidosOficina::version($pedido) }}">
                <label for="motivo" class="block text-sm font-medium text-gray-700">Motivo</label>
                <textarea id="motivo" name="motivo" rows="3" maxlength="500" required aria-describedby="motivo-ayuda"
                          class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('motivo') }}</textarea>
                <p id="motivo-ayuda" class="mt-1 text-xs text-gray-500">Queda en la auditoría con quién lo anuló.</p>
                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />

                <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                    <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Anular pedido</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
