<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Salidas</h2>
            @can('rutas.gestionar')
                <a href="{{ route('rutas.salidas.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">+ Nueva salida</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Ruta</label>
                    <select name="ruta_id" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        @foreach ($rutas as $r)
                            <option value="{{ $r->id }}" @selected(request('ruta_id') == $r->id)>{{ $r->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select name="estado" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        @foreach ($estados as $estado)
                            <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>{{ $estado->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['ruta_id', 'estado']))
                    <a href="{{ route('rutas.salidas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Ruta</th>
                                <th class="py-3 px-4">Inicio</th>
                                <th class="py-3 px-4">Regreso</th>
                                <th class="py-3 px-4">Vendedores</th>
                                <th class="py-3 px-4 text-center">Documentos</th>
                                <th class="py-3 px-4 text-center">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($salidas as $salida)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="hover:underline">{{ $salida->ruta->nombre }}</a>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->fecha_inicio->translatedFormat('d M Y') }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">
                                        @if ($salida->fecha_fin_real)
                                            {{ $salida->fecha_fin_real->translatedFormat('d M Y') }}
                                        @elseif ($salida->fecha_fin_estimada)
                                            <span class="text-gray-400 dark:text-paper-500" title="Estimado">~ {{ $salida->fecha_fin_estimada->translatedFormat('d M Y') }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->personal->pluck('nombre')->implode(' · ') ?: '—' }}</td>
                                    {{-- Cuántos documentos lleva la salida. El desglose
                                         (entregados, papel, NC) está en el detalle: en un
                                         listado, cinco números por fila no se leen. --}}
                                    <td class="py-3 px-4 text-center {{ $salida->documentos_count > 0 ? 'font-medium text-gray-700 dark:text-paper-200' : 'text-gray-400 dark:text-paper-500' }}">
                                        {{ $salida->documentos_count }}
                                    </td>
                                    <td class="py-3 px-4 text-center"><x-rutas.estado-badge :estado="$salida->estado" /></td>
                                    <td class="py-3 px-4 text-right">
                                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                        No hay salidas que coincidan.
                                        @can('rutas.gestionar')
                                            <a href="{{ route('rutas.salidas.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Crear una</a>.
                                        @endcan
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
