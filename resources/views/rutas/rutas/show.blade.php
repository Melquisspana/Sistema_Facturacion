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

                {{-- ============ Columna derecha: buscar y asignar ============ --}}
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Agregar salas</h3>
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
                            Buscá por nombre o código. Nada se asigna solo: siempre hay que confirmarlo acá.
                        </p>
                    </div>

                    {{-- El buscador NO vuelca las 135 sucursales de golpe: hasta que
                         no haya un criterio, no se lista nada. --}}
                    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-gray-100 px-5 py-4 dark:border-ink-700">
                        <div class="min-w-0 flex-1">
                            <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Buscar sala</label>
                            <input type="text" name="q" value="{{ request('q') }}" placeholder="Nombre o código…"
                                   class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-paper-400">Cliente</label>
                            <select name="cliente_id" class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                <option value="">Todos</option>
                                @foreach ($clientes as $cliente)
                                    <option value="{{ $cliente->id }}" @selected(request('cliente_id') == $cliente->id)>{{ $cliente->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Buscar</button>
                        @if ($busco)
                            <a href="{{ route('rutas.rutas.show', $ruta) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                        @endif
                        <label class="flex w-full items-center gap-2 pt-1">
                            <input type="checkbox" name="incluir_inactivas" value="1" @checked(request()->boolean('incluir_inactivas'))
                                   class="rounded border-gray-300 text-indigo-600 dark:border-ink-600 dark:bg-ink-800">
                            <span class="text-xs text-gray-500 dark:text-paper-400">Incluir salas inactivas</span>
                        </label>
                    </form>

                    @if (! $busco)
                        <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-paper-400">
                            Escribí algo para buscar.<br>
                            <span class="text-xs text-gray-400 dark:text-paper-500">
                                O <a href="{{ route('rutas.rutas.show', [$ruta, 'todas' => 1]) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">ver todas las salas</a> página por página.
                            </span>
                        </p>
                    @elseif ($candidatas->isEmpty())
                        <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-paper-400">
                            Ninguna sala coincide con la búsqueda.
                        </p>
                    @else
                        @can('rutas.gestionar')
                            <form method="POST" action="{{ route('rutas.rutas.salas.store', $ruta) }}">
                                @csrf
                        @endcan

                        <div class="max-h-[24rem] overflow-y-auto divide-y divide-gray-100 dark:divide-ink-700">
                            @foreach ($candidatas as $sala)
                                <label class="flex cursor-pointer items-center gap-3 px-5 py-3 hover:bg-gray-50 dark:hover:bg-ink-700">
                                    @can('rutas.gestionar')
                                        <input type="checkbox" name="sucursales[]" value="{{ $sala->id }}"
                                               class="shrink-0 rounded border-gray-300 text-indigo-600 dark:border-ink-600 dark:bg-ink-800">
                                    @endcan
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-800 dark:text-paper-100">{{ $sala->nombre }}</span>
                                        <span class="block truncate text-xs text-gray-400 dark:text-paper-500">
                                            {{ $sala->codigo ? $sala->codigo.' · ' : '' }}{{ $sala->cliente?->nombre }}
                                            @unless ($sala->activo)
                                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 dark:bg-ink-700 dark:text-paper-400">inactiva</span>
                                            @endunless
                                        </span>
                                    </span>
                                    {{-- Que ya tenga otra ruta se avisa ACÁ y no después:
                                         asignarla la mueve, y eso debe verse antes de hacerlo. --}}
                                    @if ($sala->ruta)
                                        <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                                              title="Asignarla acá la mueve de ruta">
                                            {{ $sala->ruta->nombre }}
                                        </span>
                                    @endif
                                </label>
                            @endforeach
                        </div>

                        @can('rutas.gestionar')
                                <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 dark:border-ink-700">
                                    <p class="text-xs text-gray-400 dark:text-paper-500">
                                        Marcá las que quieras y confirmá. Las que ya tienen ruta se moverán a «{{ $ruta->nombre }}».
                                    </p>
                                    <button class="shrink-0 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        Asignar a la ruta
                                    </button>
                                </div>
                            </form>
                        @endcan

                        <div class="px-5 pb-4">{{ $candidatas->links() }}</div>
                    @endif
                </div>
            </div>

            {{-- Salidas de esta ruta. Todavía sin documentos: el bloque siguiente
                 traerá el seguimiento de CCF/albaranes. --}}
            <div class="mt-8">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-paper-200">Salidas de esta ruta</h3>
                    <a href="{{ route('rutas.salidas.index', ['ruta_id' => $ruta->id]) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Ver todas</a>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                    <div class="divide-y divide-gray-100 dark:divide-ink-700">
                        @forelse ($ruta->salidas()->with('vendedores:id,name')->orderByDesc('fecha_inicio')->limit(5)->get() as $salida)
                            <a href="{{ route('rutas.salidas.show', $salida) }}" class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50 dark:hover:bg-ink-700">
                                <span class="text-sm text-gray-700 dark:text-paper-200">{{ $salida->periodoLegible() }}</span>
                                <span class="truncate text-xs text-gray-500 dark:text-paper-400">{{ $salida->vendedores->pluck('name')->implode(' · ') ?: '—' }}</span>
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
