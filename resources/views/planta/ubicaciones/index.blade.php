<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Ubicaciones</h2>
            @can('planta.catalogos.gestionar')
                <a href="{{ route('planta.ubicaciones.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva ubicación</a>
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
                    <a href="{{ route('planta.ubicaciones.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
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
                                <th class="py-3 px-4 text-center">Sistema</th>
                                <th class="py-3 px-4 text-center">Operación manual</th>
                                <th class="py-3 px-4 text-right">Orden</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($ubicaciones as $ubicacion)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $ubicacion->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4 font-mono text-xs text-gray-600 dark:text-paper-300">{{ $ubicacion->codigo }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">{{ $ubicacion->nombre }}</td>
                                    <td class="py-3 px-4">
                                        <x-planta.badge :color="$ubicacion->tipo->color()">{{ $ubicacion->tipo->label() }}</x-planta.badge>
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">{{ $ubicacion->es_sistema ? 'Sí' : 'No' }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-paper-300">{{ $ubicacion->permite_operacion_manual ? 'Sí' : 'No' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 dark:text-paper-300">{{ $ubicacion->orden }}</td>
                                    <td class="py-3 px-4 text-center">
                                        {{-- Una ubicación de sistema activa no se puede desactivar: el botón
                                             se muestra inerte para no ofrecer una acción que devolverá 403. --}}
                                        <x-planta.toggle-activo :activo="$ubicacion->activo"
                                                                :accion="route('planta.ubicaciones.toggle-activo', $ubicacion)"
                                                                :bloqueado="$ubicacion->es_sistema && $ubicacion->activo"
                                                                motivo-bloqueo="Las ubicaciones de sistema no se pueden desactivar." />
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.ubicaciones.edit', $ubicacion) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay ubicaciones registradas.
                                        @can('planta.catalogos.gestionar')
                                            <a href="{{ route('planta.ubicaciones.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Cree la primera</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($ubicaciones->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-ink-700">{{ $ubicaciones->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
