<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">{{ $ruta->nombre }}</h2>
                @if (! $ruta->activa)
                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-ink-700 dark:text-paper-400">Inactiva</span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('rutas.rutas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver</a>
                @can('rutas.gestionar')
                    <a href="{{ route('rutas.rutas.edit', $ruta) }}"
                       class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Editar ruta</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ============ Cobertura: qué lugares atiende la ruta ============
                 Solo alimenta la PROPUESTA de ruta de cada sala; agregar o quitar un
                 lugar no mueve ninguna sala. --}}
            <div id="cobertura" class="mb-6 bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Cobertura</h3>
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
                            Departamentos completos y distritos que atiende. Con esto se propone la ruta de cada sala.
                            @if ($ruta->frecuencia_objetivo_dias)
                                Sale más o menos cada {{ $ruta->frecuencia_objetivo_dias }} días.
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('rutas.asignacion.index', ['ruta_id' => $ruta->id]) }}"
                       class="shrink-0 text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ver salas propuestas →</a>
                </div>

                <div class="flex flex-wrap gap-2 px-5 py-4">
                    @forelse ($coberturas as $cobertura)
                        <span class="inline-flex items-center gap-1.5 rounded-full py-1 pl-3 pr-1.5 text-xs font-medium {{ $cobertura->esDepartamento() ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' : 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' }}">
                            {{ $cobertura->etiqueta() }}
                            @can('rutas.gestionar')
                                <form method="POST" action="{{ route('rutas.rutas.cobertura.destroy', [$ruta, $cobertura]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Quitar de la cobertura" aria-label="Quitar {{ $cobertura->etiqueta() }}"
                                            class="rounded-full px-1.5 leading-5 opacity-60 hover:bg-white/70 hover:opacity-100 dark:hover:bg-ink-700">×</button>
                                </form>
                            @endcan
                        </span>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-paper-400">Todavía no cubre ningún lugar.</p>
                    @endforelse
                </div>

                @can('rutas.gestionar')
                    <div class="grid grid-cols-1 gap-4 border-t border-gray-100 px-5 py-4 sm:grid-cols-2 dark:border-ink-700">
                        <form method="POST" action="{{ route('rutas.rutas.cobertura.store', $ruta) }}" class="flex items-end gap-2">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <label for="departamento_id" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Departamento completo</label>
                                <select id="departamento_id" name="departamento_id" required
                                        class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                    <option value="">Elegí…</option>
                                    @foreach ($departamentos as $departamento)
                                        <option value="{{ $departamento->id }}">{{ $departamento->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button class="shrink-0 rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Agregar</button>
                        </form>

                        <form method="POST" action="{{ route('rutas.rutas.cobertura.store', $ruta) }}" class="flex items-end gap-2">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <label for="distrito_id" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Solo un distrito (pueblo)</label>
                                <select id="distrito_id" name="distrito_id" required
                                        class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                    <option value="">Elegí…</option>
                                    @foreach ($distritos as $nombreDepartamento => $lista)
                                        <optgroup label="{{ $nombreDepartamento }}">
                                            @foreach ($lista as $distrito)
                                                <option value="{{ $distrito->id }}">{{ $distrito->nombre }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <button class="shrink-0 rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Agregar</button>
                        </form>
                    </div>
                @endcan
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                {{-- ============ Columna izquierda: lo que YA está en la ruta ============ --}}
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Salas habituales</h3>
                        <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                            {{ $asignadas->count() }}
                        </span>
                    </div>

                    <div class="max-h-[32rem] overflow-y-auto divide-y divide-gray-100 dark:divide-ink-700">
                        @forelse ($asignadas as $sala)
                            <div class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-800 dark:text-paper-100">{{ $sala->nombre }}</p>
                                    <p class="truncate text-xs text-gray-400 dark:text-paper-500">
                                        {{ $sala->codigo ? $sala->codigo.' · ' : '' }}{{ $sala->cliente?->nombre }}
                                        @unless ($sala->activo)
                                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 dark:bg-ink-700 dark:text-paper-400">sala inactiva</span>
                                        @endunless
                                    </p>
                                    @php $visita = $ultimasVisitas[$sala->id] ?? null; @endphp
                                    <p class="text-xs {{ $visita ? 'text-gray-500 dark:text-paper-400' : 'text-gray-400 dark:text-paper-500' }}">
                                        @if ($visita)
                                            Última visita {{ $visita->translatedFormat('d M') }}
                                            ({{ $visita->isToday() ? 'hoy' : 'hace '.(int) $visita->diffInDays(today()).' '.((int) $visita->diffInDays(today()) === 1 ? 'día' : 'días') }})
                                        @else
                                            Sin visitas registradas
                                        @endif
                                    </p>
                                </div>
                                @can('rutas.gestionar')
                                    <form method="POST" action="{{ route('rutas.rutas.salas.destroy', [$ruta, $sala]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="shrink-0 rounded-md px-2 py-1 text-xs text-gray-500 hover:bg-red-50 hover:text-red-600 dark:text-paper-400 dark:hover:bg-red-500/10 dark:hover:text-red-300">
                                            Quitar
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        @empty
                            <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-paper-400">
                                Esta ruta todavía no tiene salas.<br>
                                <span class="text-xs text-gray-400 dark:text-paper-500">Buscalas en el panel de la derecha y asignalas.</span>
                            </p>
                        @endforelse
                    </div>
                </div>

                {{-- ============ Columna derecha: buscar y agregar ============ --}}
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Agregar salas</h3>
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
                            Escribí y aparecen: por nombre, código, cliente o pueblo. Si una ya está en otra ruta, agregarla la mueve acá.
                        </p>
                    </div>
                    <div class="px-5 py-4">
                        @can('rutas.gestionar')
                            <x-rutas.buscador-salas :ruta="$ruta" />
                        @else
                            <p class="text-sm text-gray-500 dark:text-paper-400">No tenés permiso para cambiar las salas de la ruta.</p>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Salidas de esta ruta. --}}
            <div class="mt-8">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Salidas de esta ruta</h3>
                    <a href="{{ route('rutas.salidas.index', ['ruta_id' => $ruta->id]) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ver todas</a>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="divide-y divide-gray-100 dark:divide-ink-700">
                        @forelse ($ruta->salidas()->with('personal:id,nombre')->orderByDesc('fecha_inicio')->limit(5)->get() as $salida)
                            <a href="{{ route('rutas.salidas.show', $salida) }}" class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50 dark:hover:bg-ink-700">
                                <span class="text-sm text-gray-700 dark:text-paper-200">{{ $salida->periodoLegible() }}</span>
                                <span class="truncate text-xs text-gray-500 dark:text-paper-400">{{ $salida->personal->pluck('nombre')->implode(' · ') ?: '—' }}</span>
                                <x-rutas.estado-badge :estado="$salida->estado" />
                            </a>
                        @empty
                            <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-paper-400">Esta ruta todavía no tiene salidas.</p>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
