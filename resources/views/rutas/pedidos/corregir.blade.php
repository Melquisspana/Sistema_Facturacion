{{-- La oficina corrige un pedido de sala (Melqui, 13/11/2026; docs/GUIA_PANTALLAS.md,
     «Formularios»): la sala, si el vendedor se equivocó, y las cantidades de lo que falta
     mandar, con el motivo. La regla está en PedidosOficina; acá solo se dibuja.

     La sala se busca con un formulario GET aparte (`?q=`, como en la tienda nueva): va arriba
     porque recarga la página. Los botones llevan las clases de la guía escritas a mano, como
     el detalle del pedido: x-primary-button y x-secondary-button todavía no tienen esa forma.

     Variables: $pedido (líneas con lo mandado, envíos y sala), $texto (lo buscado) y $salas. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Corregir pedido de {{ $pedido->nombreSala() }}</h1>
            <a href="{{ route('rutas.pedidos.show', $pedido) }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al pedido</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $principal = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700';
        $porMandar = $pedido->estaPorMandar();
        $salaElegida = (int) old('cliente_sucursal_id', $pedido->cliente_sucursal_id);
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto space-y-4 px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <p class="text-sm text-gray-600">Pedido del {{ $pedido->fecha->format('d/m/Y') }}, lo sacó {{ $pedido->sacadoPor?->nombre ?? '—' }}. Lo que ya se mandó no se toca.</p>

            {{-- La sala: la búsqueda recarga la página, así que va antes de las cantidades. --}}
            <section class="{{ $caja }}" aria-labelledby="sala-titulo">
                <h2 id="sala-titulo" class="text-base font-semibold text-gray-800">Sala</h2>
                @if ($pedido->esTiendaNueva())
                    <p class="mt-1 text-sm text-gray-600">Es una tienda nueva: su sala se enlaza con «Dar de alta la tienda».</p>
                @else
                    <p class="mt-1 text-sm text-gray-600">Si el vendedor se equivocó de sala, buscá la correcta y elegila abajo.</p>
                    <form id="buscar-sala-form" method="GET" action="{{ route('rutas.pedidos.corregir', $pedido) }}" class="mt-3" role="search">
                        <label for="buscar-sala" class="block text-sm font-medium text-gray-700">Buscar sala</label>
                        <div class="mt-1 flex gap-2">
                            <input id="buscar-sala" name="q" type="search" value="{{ $texto }}" maxlength="100" autocomplete="off"
                                   placeholder="Nombre, código, cliente o pueblo" class="min-h-11 w-full rounded-md border-gray-300 text-sm">
                            <button class="{{ $secundario }} shrink-0">Buscar</button>
                        </div>
                    </form>
                    @if (mb_strlen($texto) >= 2 && $salas->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">Ninguna otra sala coincide con «{{ $texto }}».</p>
                    @endif
                @endif
            </section>

            <form method="POST" action="{{ route('rutas.pedidos.corregir.guardar', $pedido) }}" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="version" value="{{ \App\Services\Rutas\PedidosOficina::version($pedido) }}">

                @unless ($pedido->esTiendaNueva())
                    <fieldset class="{{ $caja }}">
                        <legend class="sr-only">Sala del pedido</legend>
                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                            <li>
                                <label class="flex min-h-11 items-center gap-3 px-4 py-2">
                                    <input type="radio" name="cliente_sucursal_id" value="{{ $pedido->cliente_sucursal_id }}" @checked($salaElegida === $pedido->cliente_sucursal_id)>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-800">Dejar en {{ $pedido->nombreSala() }}</span>
                                        <span class="block text-xs text-gray-500">La sala de ahora{{ $pedido->sala?->codigo ? ' · '.$pedido->sala->codigo : '' }}</span>
                                    </span>
                                </label>
                            </li>
                            @foreach ($salas as $sala)
                                <li>
                                    <label class="flex min-h-11 items-center gap-3 px-4 py-2">
                                        <input type="radio" name="cliente_sucursal_id" value="{{ $sala->id }}" @checked($salaElegida === $sala->id)>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-800">{{ $sala->nombre }}</span>
                                            <span class="block text-xs text-gray-500">{{ collect([$sala->codigo, $sala->distrito?->nombre, $sala->cliente?->nombre])->filter()->implode(' · ') }}</span>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <x-input-error :messages="$errors->get('cliente_sucursal_id')" class="mt-1" />
                    </fieldset>
                @endunless

                <section class="{{ $caja }}" aria-labelledby="productos-titulo">
                    <h2 id="productos-titulo" class="text-base font-semibold text-gray-800">Productos</h2>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $porMandar
                            ? 'Cambiá lo que pide la sala. No puede quedar en menos de lo que ya se mandó. Para que no se traiga lo que falta, dejalo en lo mandado. Para quitar un producto que no se mandó, poné 0.'
                            : 'El pedido ya está cerrado: las cantidades ya no se corrigen. Sí se puede cambiar la sala.' }}
                    </p>
                    <ul class="mt-2 divide-y divide-gray-100">
                        @foreach ($pedido->lineas as $linea)
                            @php $mandado = $linea->mandado(); @endphp
                            <li class="flex items-center justify-between gap-3 py-2">
                                <label for="cantidad-{{ $linea->id }}" class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-800">{{ $linea->producto?->nombre }}</span>
                                    <span class="block text-xs text-gray-500">Pidió {{ $linea->cantidadLegible() }} · mandado {{ \App\Models\PedidoSalaLinea::legible($mandado) }}</span>
                                    <x-input-error :messages="$errors->get('cantidades.'.$linea->id)" class="mt-1" />
                                </label>
                                @if ($porMandar)
                                    <input id="cantidad-{{ $linea->id }}" name="cantidades[{{ $linea->id }}]" type="number" inputmode="decimal"
                                           min="{{ (float) $mandado }}" max="{{ \App\Models\PedidoSala::CANTIDAD_MAXIMA }}" step="any" required
                                           value="{{ old('cantidades.'.$linea->id, (float) $linea->cantidad) }}"
                                           class="min-h-11 w-28 shrink-0 rounded-md border-gray-300 text-right text-sm tabular-nums">
                                @else
                                    <span class="shrink-0 text-sm tabular-nums text-gray-800">{{ $linea->cantidadLegible() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <div class="{{ $caja }}">
                    <label for="motivo" class="block text-sm font-medium text-gray-700">Motivo</label>
                    <textarea id="motivo" name="motivo" rows="3" maxlength="500" required aria-describedby="motivo-ayuda"
                              class="mt-1 w-full rounded-md border-gray-300 text-sm">{{ old('motivo') }}</textarea>
                    <p id="motivo-ayuda" class="mt-1 text-xs text-gray-500">Queda en la auditoría con quién lo corrigió y qué cambió.</p>
                    <x-input-error :messages="$errors->get('motivo')" class="mt-1" />

                    <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="{{ $secundario }}">Cancelar</a>
                        <button class="{{ $principal }}">Guardar corrección</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
