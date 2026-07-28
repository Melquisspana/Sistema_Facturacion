{{-- Catálogo de insumos de Planta. Solo lectura para quien no tiene
     planta.catalogos.gestionar: los botones se ocultan, pero quien manda es el
     middleware de la ruta y el refuerzo del controlador. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Insumos</h2>
            @can('planta.catalogos.gestionar')
                <a href="{{ route('planta.insumos.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo insumo</a>
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
                           class="mt-1 w-64 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Tipo</label>
                    <select name="tipo" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        @foreach ($tipos as $tipo)
                            <option value="{{ $tipo->value }}" @selected(request('tipo') === $tipo->value)>{{ $tipo->label() }}</option>
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
                @if (request()->hasAny(['q', 'tipo', 'activo']))
                    <a href="{{ route('planta.insumos.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Código</th>
                                <th class="py-3 px-4">Nombre</th>
                                <th class="py-3 px-4">Tipo</th>
                                <th class="py-3 px-4">Unidad base</th>
                                <th class="py-3 px-4 text-center">Controla lotes</th>
                                <th class="py-3 px-4 text-center">Permite fracción</th>
                                <th class="py-3 px-4 text-right">Stock mínimo</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($insumos as $insumo)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $insumo->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4 font-mono text-xs text-gray-600 dark:text-paper-300">{{ $insumo->codigo }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">{{ $insumo->nombre }}</td>
                                    <td class="py-3 px-4">
                                        <x-planta.badge :color="$insumo->tipo->color()">{{ $insumo->tipo->label() }}</x-planta.badge>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $insumo->unidad_base->label() }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">{{ $insumo->controla_lotes ? 'Sí' : 'No' }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">{{ $insumo->permite_fraccion ? 'Sí' : 'No' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 dark:text-paper-300">
                                        {{ $insumo->stock_minimo !== null ? rtrim(rtrim($insumo->stock_minimo, '0'), '.') : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <x-planta.toggle-activo :activo="$insumo->activo"
                                                                :accion="route('planta.insumos.toggle-activo', $insumo)" />
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.insumos.edit', $insumo) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay insumos registrados.
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.insumos.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Cree el primero</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($insumos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-ink-700">{{ $insumos->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
