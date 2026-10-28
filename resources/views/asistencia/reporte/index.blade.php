<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Reporte de asistencia</h2>
    </x-slot>
    @php
        $rango = ['desde' => $filtro->desde->format('Y-m-d'), 'hasta' => $filtro->hasta->format('Y-m-d')];
        $exacto = $filas->every(fn ($fila) => $fila['exacto']);
    @endphp
    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            <x-asistencia.avisos />
            <form method="GET" action="{{ route('asistencia.reporte') }}" class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                @foreach (['desde' => 'Desde', 'hasta' => 'Hasta'] as $campo => $texto)
                    <div>
                        <label for="{{ $campo }}" class="block text-xs font-medium text-gray-500 dark:text-paper-400">{{ $texto }}</label>
                        <input id="{{ $campo }}" type="date" name="{{ $campo }}" value="{{ old($campo, $rango[$campo]) }}" required class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                    </div>
                @endforeach
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Consultar</button>
                <a href="{{ route('asistencia.reporte.excel', $rango) }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:text-paper-200 dark:hover:bg-ink-700">Excel</a>
            </form>
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                            <th scope="col" class="py-2 pr-4">Persona</th><th scope="col" class="py-2 pr-4">Días trabajados</th><th scope="col" class="py-2 pr-4">Horas</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100 text-gray-800 dark:divide-ink-700 dark:text-paper-200">
                            @forelse ($filas as $fila)
                                <tr><td class="py-3 pr-4">{{ $fila['nombre'] }}</td><td class="py-3 pr-4 tabular-nums">{{ $fila['dias'] }}</td><td class="py-3 pr-4 tabular-nums">{{ number_format($fila['horas'], 2) }} @unless ($fila['exacto']) <span class="text-xs text-gray-500 dark:text-paper-400">(mínimo)</span> @endunless</td></tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-gray-500 dark:text-paper-400">No hay jornadas en este rango. Probá con otras fechas.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr class="border-t border-gray-200 font-semibold text-gray-800 dark:border-ink-600 dark:text-paper-100">
                            <th scope="row" class="py-3 pr-4 text-left">Total · {{ $filas->count() }} persona(s)</th><td class="py-3 pr-4 tabular-nums">{{ $filas->sum('dias') }}</td><td class="py-3 pr-4 tabular-nums">{{ number_format($filas->sum('horas'), 2) }} @unless ($exacto) (mínimo) @endunless</td>
                        </tr></tfoot>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-paper-400">Los días son fechas con jornada. Las horas suman los tramos cerrados; si quedó una jornada abierta, el tiempo es un mínimo.</p>
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <a href="{{ route('asistencia.jornadas.index', $rango) }}" class="text-indigo-600 hover:underline dark:text-indigo-300">Ver detalle de jornadas</a>
                    <a href="{{ route('asistencia.marcaciones.index', $rango) }}" class="text-indigo-600 hover:underline dark:text-indigo-300">Ver detalle de marcaciones</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
