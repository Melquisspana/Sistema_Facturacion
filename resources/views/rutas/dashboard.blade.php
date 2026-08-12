<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Rutas / Cobros</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- Solo números que ya existen de verdad. Dinero trabado, documentos
                 faltantes, NC pendientes y próxima ruta llegan cuando exista el
                 seguimiento documental. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @php
                    $tarjeta = 'rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
                    $rotulo = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-400';
                    $numero = 'mt-1 text-3xl font-semibold text-gray-800 dark:text-paper-100';
                @endphp

                <a href="{{ route('rutas.rutas.index', ['activa' => 1]) }}" class="{{ $tarjeta }} transition hover:ring-indigo-300">
                    <p class="{{ $rotulo }}">Rutas activas</p>
                    <p class="{{ $numero }}">{{ $rutasActivas }}</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">de {{ $rutasTotales }} en total</p>
                </a>

                <a href="{{ route('rutas.salidas.index', ['estado' => 'en_curso']) }}" class="{{ $tarjeta }} transition hover:ring-amber-300">
                    <p class="{{ $rotulo }}">Salidas en curso</p>
                    <p class="{{ $numero }}">{{ $enCurso }}</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">viajando ahora mismo</p>
                </a>

                <a href="{{ route('rutas.salidas.index', ['estado' => 'planificada']) }}" class="{{ $tarjeta }} transition hover:ring-indigo-300">
                    <p class="{{ $rotulo }}">Salidas planificadas</p>
                    <p class="{{ $numero }}">{{ $planificadas }}</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">todavía sin arrancar</p>
                </a>

                <div class="{{ $tarjeta }}">
                    <p class="{{ $rotulo }}">Salas sin ruta</p>
                    <p class="{{ $numero }}">{{ $salasSinRuta }}</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">activas, sin ruta habitual</p>
                </div>
            </div>

            <div class="mt-8">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Últimas salidas</h3>
                    <a href="{{ route('rutas.salidas.index') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ver todas</a>
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                    <th class="py-3 px-4">Ruta</th>
                                    <th class="py-3 px-4">Fechas</th>
                                    <th class="py-3 px-4">Vendedores</th>
                                    <th class="py-3 px-4">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @forelse ($ultimas as $salida)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                        <td class="py-3 px-4 font-medium text-gray-800 dark:text-paper-100">
                                            <a href="{{ route('rutas.salidas.show', $salida) }}" class="hover:underline">{{ $salida->ruta->nombre }}</a>
                                        </td>
                                        <td class="py-3 px-4 text-gray-600 dark:text-paper-300">{{ $salida->periodoLegible() }}</td>
                                        <td class="py-3 px-4 text-gray-600 dark:text-paper-300">
                                            {{ $salida->vendedores->pluck('name')->implode(' · ') ?: '—' }}
                                        </td>
                                        <td class="py-3 px-4"><x-rutas.estado-badge :estado="$salida->estado" /></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-8 px-4 text-center text-gray-500 dark:text-paper-400">
                                            Todavía no hay salidas registradas.
                                            @can('rutas.gestionar')
                                                <a href="{{ route('rutas.salidas.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Crear la primera</a>.
                                            @endcan
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
