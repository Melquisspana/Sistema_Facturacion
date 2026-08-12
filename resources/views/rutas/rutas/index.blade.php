<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Rutas</h2>
            @can('rutas.gestionar')
                <a href="{{ route('rutas.rutas.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva ruta</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Buscar</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Nombre de la ruta…"
                           class="mt-1 w-72 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select name="activa" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        <option value="1" @selected(request('activa') === '1')>Activas</option>
                        <option value="0" @selected(request('activa') === '0')>Inactivas</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['q', 'activa']))
                    <a href="{{ route('rutas.rutas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Ruta</th>
                                <th class="py-3 px-4 text-center">Salas habituales</th>
                                <th class="py-3 px-4 text-center">Frecuencia objetivo</th>
                                <th class="py-3 px-4 text-center">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($rutas as $ruta)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $ruta->activa ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.rutas.show', $ruta) }}" class="hover:underline">{{ $ruta->nombre }}</a>
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">{{ $ruta->sucursales_count }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">
                                        {{ $ruta->frecuencia_objetivo_dias ? 'cada '.$ruta->frecuencia_objetivo_dias.' días' : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        @php
                                            $clasesEstado = $ruta->activa
                                                ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
                                                : 'bg-gray-100 text-gray-500 dark:bg-ink-700 dark:text-paper-400';
                                        @endphp
                                        @can('rutas.gestionar')
                                            <form method="POST" action="{{ route('rutas.rutas.toggle-activa', $ruta) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" title="Cambiar estado"
                                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clasesEstado }}">
                                                    {{ $ruta->activa ? 'Activa' : 'Inactiva' }}
                                                </button>
                                            </form>
                                        @else
                                            <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $clasesEstado }}">
                                                {{ $ruta->activa ? 'Activa' : 'Inactiva' }}
                                            </span>
                                        @endcan
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <a href="{{ route('rutas.rutas.show', $ruta) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver salas</a>
                                        @can('rutas.gestionar')
                                            <span class="text-gray-300 dark:text-ink-600">·</span>
                                            <a href="{{ route('rutas.rutas.edit', $ruta) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                        No hay rutas todavía.
                                        @can('rutas.gestionar')
                                            <a href="{{ route('rutas.rutas.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Crear la primera</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">{{ $rutas->links() }}</div>

        </div>
    </div>
</x-app-layout>
