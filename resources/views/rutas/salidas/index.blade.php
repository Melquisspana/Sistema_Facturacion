<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            {{-- Todos: los viajes abiertos y cerrados, y las salidas armadas a mano de antes. Acá no se arma nada. --}}
            <h1 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Todos los viajes</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="f-zona" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Zona</label>
                    <select id="f-zona" name="ruta_id" class="mt-1 block min-h-11 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        @foreach ($rutas as $r)
                            <option value="{{ $r->id }}" @selected(request('ruta_id') == $r->id)>{{ $r->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-estado" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Estado</label>
                    <select id="f-estado" name="estado" class="mt-1 block min-h-11 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        {{-- Los mismos nombres de la lista (SalidaRuta::estadoLegible()). --}}
                        @foreach ($estados as $estado)
                            <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>{{ ['planificada' => 'Programados', 'en_curso' => 'Abiertos', 'finalizada' => 'Cerrados', 'cancelada' => 'Cancelados'][$estado->value] ?? $estado->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">Filtrar</button>
                @if (request()->hasAny(['ruta_id', 'estado']))
                    <a href="{{ route('rutas.salidas.index') }}" class="inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline dark:text-indigo-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-300 dark:border-ink-600">
                                <th class="py-3 px-4">Viaje</th>
                                <th class="py-3 px-4">Inicio</th>
                                <th class="py-3 px-4">Regreso</th>
                                <th class="py-3 px-4">Vendedores</th>
                                <th class="whitespace-nowrap py-3 px-4 text-center">CCF entregados</th>
                                <th class="py-3 px-4 text-center">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($salidas as $salida)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="hover:underline">{{ $salida->titulo() }}</a>
                                    </td>
                                    <td class="whitespace-nowrap py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->fecha_inicio->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap py-3 px-4 text-gray-600 dark:text-paper-300">
                                        @if ($salida->fecha_fin_real)
                                            {{ $salida->fecha_fin_real->format('d/m/Y') }}
                                        @elseif ($salida->fecha_fin_estimada)
                                            <span class="text-gray-400 dark:text-paper-500" title="Estimado">~ {{ $salida->fecha_fin_estimada->format('d/m/Y') }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->personal->pluck('nombre')->implode(' · ') ?: '—' }}</td>
                                    <td class="py-3 px-4 text-center tabular-nums {{ $salida->entregas_count > 0 ? 'text-gray-700 dark:text-paper-100' : 'text-gray-400 dark:text-paper-500' }}">
                                        {{ $salida->entregados_count }} / {{ $salida->entregas_count }}
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <x-rutas.estado-badge :estado="$salida->estado" :texto="$salida->estadoLegible()" />
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-8 px-4 text-center text-gray-500 dark:text-paper-300">
                                        No hay viajes que coincidan. El viaje se abre solo cuando un vendedor saca el primer pedido.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">{{ $salidas->links() }}</div>

        </div>
    </div>
</x-app-layout>
