{{-- «Pedidos» de la oficina: los sugeridos que sacan los vendedores en las salas. Filtros por
     fecha, ruta y sala (docs/GUIA_PANTALLAS.md: formulario GET, «Filtrar» secundario y
     «Limpiar» solo con filtros). En el celular, tarjetas; en la computadora, tabla. Solo
     lectura: las acciones de producción llegan con su pantalla. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pedidos</h1>
        </div>
    </x-slot>

    @php
        $campo = 'mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm';
        $etiqueta = 'block text-xs font-medium text-gray-500';
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <form method="GET" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 items-end lg:grid-cols-5">
                @if (request('salida'))
                    <input type="hidden" name="salida" value="{{ request('salida') }}">
                @endif
                <div>
                    <label for="f-desde" class="{{ $etiqueta }}">Desde</label>
                    <input id="f-desde" type="date" name="desde" value="{{ request('desde') }}" class="{{ $campo }}">
                </div>
                <div>
                    <label for="f-hasta" class="{{ $etiqueta }}">Hasta</label>
                    <input id="f-hasta" type="date" name="hasta" value="{{ request('hasta') }}" class="{{ $campo }}">
                </div>
                <div>
                    <label for="f-ruta" class="{{ $etiqueta }}">Ruta</label>
                    <select id="f-ruta" name="ruta_id" class="{{ $campo }}">
                        <option value="">Todas</option>
                        @foreach ($rutas as $r)
                            <option value="{{ $r->id }}" @selected((string) request('ruta_id') === (string) $r->id)>{{ $r->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-sala" class="{{ $etiqueta }}">Sala</label>
                    <input id="f-sala" type="search" name="sala" value="{{ request('sala') }}" maxlength="100" placeholder="Nombre o código" class="{{ $campo }}">
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                    @if ($filtrado)
                        <a href="{{ route('rutas.pedidos.index') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 underline">Limpiar</a>
                    @endif
                </div>
            </form>
            @if (request('salida'))
                <p class="mb-4 text-sm text-gray-600">Solo los de una salida. <a href="{{ route('rutas.pedidos.index') }}" class="text-indigo-600 underline">Ver todos</a></p>
            @endif

            <div class="rounded-lg border border-gray-200 bg-white">
                @if ($pedidos->isEmpty())
                    <div class="px-4 py-10 text-center text-gray-500">
                        @if ($filtrado)
                            Nada coincide con estos filtros. <a href="{{ route('rutas.pedidos.index') }}" class="text-indigo-600 underline">Limpiar filtros</a>
                        @else
                            Todavía no hay pedidos. Los sacan los vendedores desde «Mi salida».
                        @endif
                    </div>
                @else
                    {{-- Celular: tarjetas. --}}
                    <ul class="divide-y divide-gray-100 sm:hidden">
                        @foreach ($pedidos as $pedido)
                            <li class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="min-w-0 font-medium text-indigo-600 underline">{{ $pedido->sala?->nombre }}</a>
                                    <x-rutas.estado-badge :estado="$pedido->estado" class="shrink-0" />
                                </div>
                                <p class="mt-1 text-sm text-gray-600">{{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->ruta?->nombre ?? 'Sin ruta' }} · {{ $pedido->sacadoPor?->nombre ?? '—' }}</p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ $pedido->lineas_count }} {{ $pedido->lineas_count === 1 ? 'producto' : 'productos' }} · {{ $pedido->numero_orden_compra ? 'OC '.$pedido->numero_orden_compra : 'Sin OC' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Computadora: tabla. --}}
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">Fecha</th>
                                    <th class="px-4 py-3">Sala</th>
                                    <th class="px-4 py-3">Ruta</th>
                                    <th class="px-4 py-3">Sacó</th>
                                    <th class="px-4 py-3 text-right">Productos</th>
                                    <th class="px-4 py-3">OC</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pedidos as $pedido)
                                    <tr>
                                        <td class="px-4 py-3 text-gray-600">{{ $pedido->fecha->format('d/m/Y') }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-800">{{ $pedido->sala?->nombre }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $pedido->ruta?->nombre ?? 'Sin ruta' }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $pedido->sacadoPor?->nombre ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $pedido->lineas_count }}</td>
                                        <td class="px-4 py-3 font-mono text-gray-600">{{ $pedido->numero_orden_compra ?? '—' }}</td>
                                        <td class="px-4 py-3"><x-rutas.estado-badge :estado="$pedido->estado" /></td>
                                        <td class="px-4 py-3 text-right"><a href="{{ route('rutas.pedidos.show', $pedido) }}" class="text-indigo-600 hover:underline">Ver</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($pedidos->hasPages())
                        <div class="border-t border-gray-100 px-4 py-3">{{ $pedidos->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
