<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Personal operativo</h2>
            @can('rutas.personal.gestionar')
                <a href="{{ route('rutas.personal.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Nueva persona
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            <p class="mb-4 max-w-3xl text-sm text-gray-500 dark:text-paper-400">
                Quiénes salen a vender, repartir o cobrar. Nadie tiene ruta fija: cualquiera puede ir a
                cualquier cliente, y quién queda a cargo se decide en cada salida.
            </p>

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="q" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Buscar</label>
                    <input type="text" name="q" id="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Nombre…"
                           class="mt-1 w-64 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label for="estado" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select name="estado" id="estado" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        <option value="activos" @selected(($filtros['estado'] ?? '') === 'activos')>Activos</option>
                        <option value="inactivos" @selected(($filtros['estado'] ?? '') === 'inactivos')>Inactivos</option>
                    </select>
                </div>
                <div>
                    <label for="funcion" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Función</label>
                    <select name="funcion" id="funcion" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Cualquiera</option>
                        @foreach ($funciones as $funcion)
                            <option value="{{ $funcion->value }}" @selected(($filtros['funcion'] ?? '') === $funcion->value)>{{ $funcion->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2">
                    Filtrar
                </button>
                @if (array_filter($filtros))
                    <a href="{{ route('rutas.personal.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                <th class="py-3 px-4">Persona</th>
                                <th class="py-3 px-4">Funciones</th>
                                <th class="py-3 px-4">Enlaces</th>
                                <th class="py-3 px-4 text-center">Salidas</th>
                                <th class="py-3 px-4 text-center">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($personal as $persona)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $persona->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.personal.show', $persona) }}" class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
                                            {{ $persona->nombre }}
                                        </a>
                                        @if ($persona->telefono)
                                            <span class="block text-xs text-gray-400 dark:text-paper-500">{{ $persona->telefono }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($persona->funcionesEnum() as $funcion)
                                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $funcion->clase() }}">{{ $funcion->label() }}</span>
                                            @empty
                                                <span class="text-xs text-gray-400 dark:text-paper-500">Sin funciones declaradas</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-xs text-gray-500 dark:text-paper-400">
                                        {{-- Los enlaces son punteros de identidad, no requisitos: casi nadie de
                                             campo tiene login, y eso es normal. --}}
                                        @if ($persona->user)
                                            <span class="block">Usuario: {{ $persona->user->name }}</span>
                                        @endif
                                        @if ($persona->asistencia_empleado_id)
                                            <span class="block">Enlazado a planilla</span>
                                        @endif
                                        @if (! $persona->user && ! $persona->asistencia_empleado_id)
                                            <span class="text-gray-400 dark:text-paper-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-center tabular-nums text-gray-600 dark:text-paper-300">{{ $persona->participaciones_count }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $persona->activo ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-400' }}">
                                            {{ $persona->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        @can('rutas.personal.gestionar')
                                            <a href="{{ route('rutas.personal.edit', $persona) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">editar</a>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay nadie dado de alta todavía.
                                        @can('rutas.personal.gestionar')
                                            <a href="{{ route('rutas.personal.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Agregá a la primera persona</a>.
                                        @endcan
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">{{ $personal->links() }}</div>
        </div>
    </div>
</x-app-layout>
