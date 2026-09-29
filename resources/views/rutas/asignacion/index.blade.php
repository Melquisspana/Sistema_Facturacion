<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Asignar salas</h2>
            <a href="{{ route('rutas.rutas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Rutas</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            @php
                $caja = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
                $sinCoberturaTotal = $sinCobertura->sum('salas');
            @endphp

            <p class="mb-4 max-w-3xl text-sm text-gray-600 dark:text-paper-300">
                La ruta de cada sala se propone según lo que cubre cada ruta: primero el distrito, después el
                departamento completo. Nada se mueve hasta que lo confirmés. La cobertura se define en la ficha
                de cada ruta.
            </p>

            {{-- Qué cubre cada ruta, de un vistazo. Una ruta sin cobertura no propone nada. --}}
            <div class="mb-6 flex flex-wrap gap-2">
                @foreach ($rutas as $ruta)
                    <a href="{{ route('rutas.rutas.show', $ruta) }}#cobertura"
                       class="rounded-full px-3 py-1 text-xs font-medium {{ $ruta->coberturas_count > 0 ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">
                        {{ $ruta->nombre }} · {{ $ruta->coberturas_count > 0 ? $ruta->coberturas_count.' '.($ruta->coberturas_count === 1 ? 'lugar' : 'lugares') : 'sin cobertura' }}
                    </a>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                {{-- ============ Propuestas: sin ruta y con ruta propuesta ============ --}}
                <div class="lg:col-span-2">
                    <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Ruta propuesta</label>
                            <select name="ruta_id" onchange="this.form.submit()"
                                    class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                <option value="">Todas</option>
                                @foreach ($rutas as $ruta)
                                    <option value="{{ $ruta->id }}" @selected($rutaId === $ruta->id)>{{ $ruta->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <noscript><button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white">Filtrar</button></noscript>
                        <p class="ml-auto text-xs text-gray-400 dark:text-paper-500">{{ $coinciden }} {{ $coinciden === 1 ? 'sala ya está' : 'salas ya están' }} donde dice la cobertura.</p>
                    </form>

                    @foreach ([
                        ['filas' => $proponer, 'titulo' => 'Sin ruta', 'detalle' => 'Salas que hoy no están en ninguna ruta y tienen una propuesta.', 'marcadas' => true],
                        ['filas' => $distintas, 'titulo' => 'En otra ruta', 'detalle' => 'Ya tienen ruta, pero la cobertura propone otra. Puede ser una excepción a propósito: solo se mueven si las marcás.', 'marcadas' => false],
                    ] as $bloque)
                        <form method="POST" action="{{ route('rutas.asignacion.aplicar') }}" class="{{ $caja }} mb-6 overflow-hidden">
                            @csrf
                            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">{{ $bloque['titulo'] }} ({{ $bloque['filas']->count() }})</h3>
                                    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">{{ $bloque['detalle'] }}</p>
                                </div>
                            </div>

                            @if ($bloque['filas']->isEmpty())
                                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-paper-400">Nada por acá.</p>
                            @else
                                <div class="max-h-[28rem] overflow-y-auto divide-y divide-gray-100 dark:divide-ink-700">
                                    @foreach ($bloque['filas'] as ['sala' => $sala, 'ruta' => $propuesta])
                                        <label class="flex cursor-pointer items-center gap-3 px-5 py-2.5 hover:bg-gray-50 dark:hover:bg-ink-700">
                                            @can('rutas.gestionar')
                                                <input type="checkbox" name="sucursales[]" value="{{ $sala->id }}" @checked($bloque['marcadas'])
                                                       class="shrink-0 rounded border-gray-300 text-indigo-600 dark:border-ink-600 dark:bg-ink-800">
                                            @endcan
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-gray-800 dark:text-paper-100">{{ $sala->nombre }}</span>
                                                <span class="block truncate text-xs text-gray-400 dark:text-paper-500">
                                                    {{ $sala->distrito?->nombre ?? '—' }} · {{ $sala->departamento?->nombre ?? '—' }}
                                                    @if ($sala->cliente) · {{ $sala->cliente->nombre }} @endif
                                                </span>
                                            </span>
                                            @if ($sala->ruta)
                                                <span class="shrink-0 text-xs text-gray-400 line-through dark:text-paper-500">{{ $sala->ruta->nombre }}</span>
                                            @endif
                                            <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                                                {{ $propuesta->nombre }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                @can('rutas.gestionar')
                                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3 dark:border-ink-700">
                                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                            Asignar las marcadas
                                        </button>
                                    </div>
                                @endcan
                            @endif
                        </form>
                    @endforeach
                </div>

                {{-- ============ Lo que ninguna ruta cubre ============ --}}
                <div>
                    <div class="{{ $caja }} overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Sin cobertura ({{ $sinCoberturaTotal }})</h3>
                            <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
                                Salas sin ruta que ninguna ruta cubre. Agregá su departamento o distrito a una ruta, o asignalas a mano desde la ficha de la ruta.
                            </p>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($sinCobertura as $grupo)
                                <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                                    <span class="text-gray-700 dark:text-paper-200">{{ $grupo['departamento'] }}</span>
                                    <span class="tabular-nums text-gray-500 dark:text-paper-400">{{ $grupo['salas'] }}</span>
                                </div>
                            @empty
                                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-paper-400">Todas las salas tienen ruta o propuesta.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
