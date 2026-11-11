<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <h2 class="truncate font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">{{ $personal->nombre }}</h2>
                <p class="text-sm text-gray-500 dark:text-paper-300">
                    {{ $personal->activo ? 'Disponible para viajes' : 'Inactivo: no sale en viajes' }}
                </p>
            </div>
            @can('rutas.personal.gestionar')
                <div class="flex shrink-0 items-center gap-2">
                    <a href="{{ route('rutas.personal.edit', $personal) }}"
                       class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100 dark:hover:bg-ink-600">
                        Editar
                    </a>
                    <form method="POST" action="{{ route('rutas.personal.toggle-activo', $personal) }}"
                          onsubmit="return confirm('{{ $personal->activo ? '¿Marcar como inactivo? Ya no podrá sacar pedidos ni entregar.' : '¿Volver a activar a esta persona?' }}')">
                        @csrf @method('PATCH')
                        <button class="rounded-md px-3 py-2 text-sm font-medium {{ $personal->activo ? 'bg-amber-100 text-amber-800 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/40 dark:text-green-300' }}">
                            {{ $personal->activo ? 'Desactivar' : 'Activar' }}
                        </button>
                    </form>
                </div>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- Ficha --}}
            <div class="bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">Funciones</dt>
                        <dd class="mt-1 flex flex-wrap gap-1">
                            @forelse ($personal->funcionesEnum() as $funcion)
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $funcion->clase() }}">{{ $funcion->label() }}</span>
                            @empty
                                <span class="text-sm text-gray-400 dark:text-paper-500">Sin funciones declaradas</span>
                            @endforelse
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">Teléfono</dt>
                        <dd class="mt-1 text-sm text-gray-800 dark:text-paper-100">
                            {{-- Se toca para llamar, igual que en el tablero. --}}
                            @if ($personal->telefonoMarcable() !== '')
                                <a href="tel:{{ $personal->telefonoMarcable() }}" class="inline-flex min-h-11 items-center text-indigo-600 hover:underline dark:text-indigo-400">{{ $personal->telefono }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">Enlaces</dt>
                        <dd class="mt-1 text-sm text-gray-800 dark:text-paper-100">
                            {{-- Que no tenga ninguno es lo normal, no una carencia. --}}
                            {{-- Quien no ve Planilla (el jefe de vendedores) no recibe un enlace a Personal. --}}
                            @if ($enPersonal && auth()->user()->can('planilla.ver'))
                                <a href="{{ route('personal.empleados.show', 'p'.$enPersonal->id) }}" class="block text-indigo-600 hover:underline dark:text-indigo-400">Ficha en Personal</a>
                            @endif
                            @if ($personal->user)
                                <span class="block">Usuario: {{ $personal->user->name }}</span>
                            @endif
                            @if ($personal->empleado)
                                <span class="block">Asistencia: {{ $personal->empleado->nombreCompleto() }}</span>
                            @endif
                            @if (! $personal->user && ! $personal->empleado && ! $enPersonal)
                                <span class="text-gray-400 dark:text-paper-500">Sin enlazar</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($personal->notas)
                    <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-600 dark:border-ink-700 dark:text-paper-300">{{ $personal->notas }}</p>
                @endif
            </div>

            {{-- Historial de viajes (y de las salidas armadas a mano de antes) --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-ink-600">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-100">Viajes ({{ $personal->participaciones->count() }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-300 dark:border-ink-600">
                                <th class="py-2.5 px-4">Viaje</th>
                                <th class="py-2.5 px-4">Papel</th>
                                <th class="py-2.5 px-4 text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($personal->participaciones->sortByDesc('id') as $participacion)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="py-2 px-4 text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('rutas.salidas.show', $participacion->salida_ruta_id) }}" class="hover:underline">
                                            {{-- La ruta y, abajo, el día de salida en d/m/Y (guía) entero: la fecha no se parte. --}}
                                            @if ($participacion->salida)
                                                <span class="block">{{ $participacion->salida->titulo() }}</span>
                                                <span class="block whitespace-nowrap text-gray-500 dark:text-paper-300">{{ $participacion->salida->fecha_inicio->format('d/m/Y') }}</span>
                                            @else
                                                —
                                            @endif
                                        </a>
                                    </td>
                                    <td class="py-2 px-4">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $participacion->rol->clase() }}">{{ $participacion->rol->label() }}</span>
                                    </td>
                                    <td class="py-2 px-4 text-center">
                                        @if ($participacion->salida)
                                            <x-rutas.estado-badge :estado="$participacion->salida->estado" :texto="$participacion->salida->estadoLegible()" />
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-gray-400 dark:text-paper-500">Todavía no fue en ningún viaje.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
