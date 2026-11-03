{{-- La lista única de empleados: una fila por persona, esté en planilla, en Asistencia o en
     las dos. La columna «Huellas» cuenta solo las VIGENTES.

     No hay botón de eliminar, y no falta: borrar a alguien borra su historial laboral.
     Quien se va se desactiva. --}}
@php
    use App\Services\Gastos\Dinero;

    $altaUnica = $vePlanilla && auth()->user()->can('planilla.gestionar');
    $altaAsistencia = ! $vePlanilla && $veAsistencia && auth()->user()->can('asistencia.gestionar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Empleados</h2>
            @if ($altaUnica)
                <div class="flex items-center gap-3">
                    <a href="{{ route('personal.repetidos') }}" class="text-sm text-gray-600 hover:underline dark:text-paper-300">Posibles repetidos</a>
                    <a href="{{ route('planilla.empleados.create') }}"
                       class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo empleado</a>
                </div>
            @elseif ($altaAsistencia)
                <a href="{{ route('asistencia.empleados.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo empleado</a>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            <x-asistencia.avisos />

            @if (session('planilla.aviso'))
                <p role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</p>
            @endif

            @if ($verImportes && $minimo === null)
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    No hay salario mínimo vigente cargado en la configuración.
                </p>
            @elseif ($verImportes && $bajoMinimo > 0)
                <p role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ $bajoMinimo === 1 ? 'Una persona queda' : $bajoMinimo.' personas quedan' }} por debajo del salario
                    mínimo vigente ({{ Dinero::mostrar($minimo) }}). El sueldo no cambia solo: actualizalo en su ficha y volvé a
                    generar el contrato.
                </p>
            @endif

            @if ($rutasSinFicha->isNotEmpty())
                <div role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <p>{{ $rutasSinFicha->count() === 1 ? '1 persona de Rutas todavía no tiene ficha en Personal' : $rutasSinFicha->count().' personas de Rutas todavía no tienen ficha en Personal' }}</p>
                    <ul>
                        @foreach ($rutasSinFicha as $personaRuta)
                            <li>
                                <a href="{{ route('rutas.personal.show', $personaRuta) }}" class="inline-flex min-h-11 items-center underline">{{ $personaRuta->nombre }}</a>
                                @can('planilla.gestionar')
                                    <a href="{{ route('planilla.empleados.create', ['personal_ruta_id' => $personaRuta->id]) }}" class="ml-3 inline-flex min-h-11 items-center underline">Darla de alta</a>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="q" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Buscar</label>
                    <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Nombre, apellido o código…"
                           class="mt-1 w-72 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label for="activo" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Estado</label>
                    <select id="activo" name="activo" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todos</option>
                        <option value="1" @selected(request('activo') === '1')>Activos</option>
                        <option value="0" @selected(request('activo') === '0')>Inactivos</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if (request()->hasAny(['q', 'activo']))
                    <a href="{{ route('personal.empleados') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Limpiar</a>
                @endif
            </form>

            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Empleados</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-300">
                                <th scope="col" class="px-4 py-3">Nombre</th>
                                <th scope="col" class="px-4 py-3">Código</th>
                                <th scope="col" class="px-4 py-3">Ingreso</th>
                                @if ($veAsistencia)
                                    <th scope="col" class="px-4 py-3">Huellas</th>
                                @endif
                                @if ($vePlanilla)
                                    <th scope="col" class="px-4 py-3">Descuentos</th>
                                @endif
                                @if ($verImportes)
                                    <th scope="col" class="px-4 py-3 text-right">Sueldo mensual</th>
                                @endif
                                <th scope="col" class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($empleados as $e)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $e['activo'] ? '' : 'opacity-60' }}">
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('personal.empleados.show', $e['clave']) }}" class="hover:underline">{{ $e['nombre'] }}</a>
                                        @unless ($e['activo'])<span class="text-xs text-gray-500 dark:text-paper-300">(inactivo)</span>@endunless
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $e['codigo'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $e['ingreso']?->format('d/m/Y') ?? '—' }}</td>
                                    @if ($veAsistencia)
                                        <td class="px-4 py-3">
                                            @if (! $e['marca'])
                                                <span class="text-gray-500 dark:text-paper-300">No marca</span>
                                            @elseif ($e['huellas'] > 0)
                                                <span class="text-gray-700 dark:text-paper-100">{{ $e['huellas'] }} asignada(s)</span>
                                            @else
                                                <span class="text-amber-700 dark:text-amber-300">Sin huella</span>
                                            @endif
                                        </td>
                                    @endif
                                    @if ($vePlanilla)
                                        <td class="px-4 py-3 text-gray-600 dark:text-paper-300">
                                            {{ $e['descuentos'] === null ? 'Sin planilla' : ($e['descuentos'] ? 'Con' : 'Sin') }}
                                        </td>
                                    @endif
                                    @if ($verImportes)
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-paper-100">
                                            @if ($e['mensual'] !== null)
                                                {{ Dinero::mostrar($e['mensual']) }}
                                                @if ($minimo !== null && $e['activo'] && $e['mensual'] < $minimo)
                                                    <span class="block text-xs font-medium text-amber-700 dark:text-amber-300">Bajo el mínimo</span>
                                                @endif
                                            @elseif ($e['descuentos'] !== null)
                                                <span class="text-xs text-amber-700 dark:text-amber-300">Sin sueldo</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('personal.empleados.show', $e['clave']) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver ficha</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        @if (request()->hasAny(['q', 'activo']))
                                            Ninguna persona coincide con el filtro.
                                        @else
                                            Todavía no hay personas dadas de alta.
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
