<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Excepciones</h2>
            <span class="text-sm text-gray-500 dark:text-paper-400">
                {{ $desde->translatedFormat('d M') }} – {{ $hasta->translatedFormat('d M Y') }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            <p class="max-w-3xl text-sm text-gray-500 dark:text-paper-400">
                Lo que no cuadra y alguien tiene que mirar. Un documento puede aparecer en más de un
                grupo: son problemas distintos y resolver uno no resuelve el otro.
                Se revisaron <strong class="tabular-nums">{{ $documentosRevisados }}</strong> documentos del período.
            </p>

            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="desde" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Desde</label>
                    <input type="date" name="desde" id="desde" value="{{ $filtros['desde'] ?? $desde->toDateString() }}"
                           class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label for="hasta" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Hasta</label>
                    <input type="date" name="hasta" id="hasta" value="{{ $filtros['hasta'] ?? $hasta->toDateString() }}"
                           class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <div>
                    <label for="ruta_id" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Ruta</label>
                    <select name="ruta_id" id="ruta_id" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        <option value="">Todas</option>
                        @foreach ($rutas as $ruta)
                            <option value="{{ $ruta->id }}" @selected(($filtros['ruta_id'] ?? '') == $ruta->id)>{{ $ruta->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2">
                    Filtrar
                </button>
            </form>

            @if ($total === 0)
                <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-800 dark:bg-green-900/20">
                    <p class="text-sm font-medium text-green-800 dark:text-green-300">No hay excepciones en este período.</p>
                    <p class="mt-1 text-xs text-green-700 dark:text-green-400">
                        Los documentos que todavía esperan su albarán no cuentan como excepción: esperar es lo normal.
                    </p>
                </div>
            @endif

            @foreach ($grupos as $clave => $documentos)
                @continue($documentos->isEmpty())

                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="border-b border-amber-200 bg-amber-50 px-5 py-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-300">
                            {{ $catalogo[$clave]['titulo'] }}
                            <span class="ml-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs tabular-nums text-amber-900 dark:bg-amber-800 dark:text-amber-200">{{ $documentos->count() }}</span>
                        </h3>
                        <p class="mt-0.5 text-xs text-amber-800 dark:text-amber-400">{{ $catalogo[$clave]['detalle'] }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200 dark:bg-ink-700 dark:text-paper-400 dark:border-ink-600">
                                    <th class="py-2.5 px-4">N.º de control</th>
                                    <th class="py-2.5 px-4">Sala</th>
                                    <th class="py-2.5 px-4">Salida</th>
                                    <th class="py-2.5 px-4">Custodia</th>
                                    <th class="py-2.5 px-4 text-right">Monto</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @foreach ($documentos as $documento)
                                    @php $custodia = $documento->estadoCustodia(); @endphp
                                    <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                        <td class="py-2 px-4 font-mono text-xs text-gray-700 dark:text-paper-200">{{ $documento->numeroLegible() }}</td>
                                        <td class="py-2 px-4 truncate text-gray-600 dark:text-paper-300">{{ $documento->salaNombre() ?? '—' }}</td>
                                        <td class="py-2 px-4 text-gray-600 dark:text-paper-300">
                                            @if ($documento->salida)
                                                <a href="{{ route('rutas.salidas.show', $documento->salida) }}" class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded">
                                                    {{ $documento->salida->descripcionCorta() }}
                                                </a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 px-4">
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $custodia->clase() }}">
                                                {{ $custodia->icono() }} {{ $custodia->label() }}
                                            </span>
                                            @if ($documento->tenedorActual())
                                                <span class="ml-1 text-xs text-gray-500 dark:text-paper-400">{{ $documento->tenedorActual()->nombre }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-4 text-right tabular-nums text-gray-800 dark:text-paper-100">
                                            {{ $documento->monto() !== null ? '$'.number_format($documento->monto(), 2) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
