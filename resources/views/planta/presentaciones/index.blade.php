<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Presentaciones</h2>
            @can('planta.catalogos.gestionar')
                <a href="{{ route('planta.presentaciones.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva presentación</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Buscar</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Código o nombre…"
                           class="mt-1 w-56 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Producto</label>
                    <select name="producto_base" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        @foreach ($productosBase as $base)
                            <option value="{{ $base->id }}" @selected(request('producto_base') == $base->id)>{{ $base->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Unidad</label>
                    <select name="unidad" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        @foreach ($unidades as $unidad)
                            <option value="{{ $unidad }}" @selected(request('unidad') === $unidad)>{{ $unidad }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select name="activo" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        <option value="1" @selected(request('activo') === '1')>Activos</option>
                        <option value="0" @selected(request('activo') === '0')>Inactivos</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['q', 'producto_base', 'unidad', 'activo']))
                    <a href="{{ route('planta.presentaciones.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Código</th>
                                <th class="py-3 px-4">Producto</th>
                                <th class="py-3 px-4">Nombre</th>
                                <th class="py-3 px-4 text-right">Contenido</th>
                                <th class="py-3 px-4">Unidad</th>
                                <th class="py-3 px-4 text-right">Unid./bulto</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($presentaciones as $presentacion)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $presentacion->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4 font-mono text-xs text-gray-600 dark:text-paper-300">{{ $presentacion->codigo }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $presentacion->productoBase?->nombre ?? '—' }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">{{ $presentacion->nombre }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 dark:text-paper-300">
                                        {{ $presentacion->contenido !== null ? rtrim(rtrim($presentacion->contenido, '0'), '.') : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $presentacion->unidad_contenido ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 dark:text-paper-300">{{ $presentacion->unidades_por_bulto ?? '—' }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <x-planta.toggle-activo :activo="$presentacion->activo"
                                                                :accion="route('planta.presentaciones.toggle-activo', $presentacion)" />
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.presentaciones.edit', $presentacion) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay presentaciones registradas.
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.presentaciones.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Cree la primera</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($presentaciones->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-ink-700">{{ $presentaciones->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
