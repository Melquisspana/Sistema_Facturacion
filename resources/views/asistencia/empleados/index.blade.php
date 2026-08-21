{{-- Listado de personas que marcan asistencia.

     La columna «Ranuras» cuenta solo las VIGENTES. Alguien con tres asignaciones
     históricas y ninguna activa no puede marcar, y la pantalla tiene que decir
     eso —«sin ranura»— y no «3».

     No hay botón de eliminar, y no falta: borrar a alguien borra su historial
     laboral. Quien se va se desactiva. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Empleados</h2>
            @can('asistencia.gestionar')
                <a href="{{ route('asistencia.empleados.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo empleado</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

            <x-asistencia.avisos />

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="q" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Buscar</label>
                    <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Nombre, apellido o código…"
                           class="mt-1 w-72 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label for="activo" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Estado</label>
                    <select id="activo" name="activo" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        <option value="1" @selected(request('activo') === '1')>Activos</option>
                        <option value="0" @selected(request('activo') === '0')>Inactivos</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['q', 'activo']))
                    <a href="{{ route('asistencia.empleados.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                @endif
            </form>

            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-400">
                                <th class="px-4 py-3">Nombre</th>
                                <th class="px-4 py-3">Código</th>
                                <th class="px-4 py-3">Ingreso</th>
                                <th class="px-4 py-3">Ranuras</th>
                                <th class="px-4 py-3 text-center">Estado</th>
                                <th class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($empleados as $empleado)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $empleado->activo ? '' : 'opacity-60' }}">
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('asistencia.empleados.show', $empleado) }}" class="hover:underline">
                                            {{ $empleado->nombreCompleto() }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $empleado->codigo ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $empleado->fecha_ingreso?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($empleado->huellas_activas_count > 0)
                                            <span class="text-gray-700 dark:text-paper-200">{{ $empleado->huellas_activas_count }} asignada(s)</span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-300">Sin ranura</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <x-asistencia.estado :activo="$empleado->activo"
                                                             :accion="route('asistencia.empleados.toggle-activo', $empleado)" />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('asistencia.empleados.show', $empleado) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver ficha</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        @if (request()->hasAny(['q', 'activo']))
                                            Ninguna persona coincide con el filtro.
                                        @else
                                            Todavía no hay personas dadas de alta.
                                            @can('asistencia.gestionar')
                                                <a href="{{ route('asistencia.empleados.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Dá de alta la primera</a>.
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($empleados->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3 dark:border-ink-700">{{ $empleados->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
