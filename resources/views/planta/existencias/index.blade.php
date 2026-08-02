{{-- Existencias: qué hay, dónde y en qué estado.

     PANTALLA DE SOLO LECTURA. No hay ni un formulario de escritura, ni un botón
     de acción, ni un enlace a «editar saldo»: no existe tal cosa. El saldo se
     corrige con un ajuste, que deja documento y motivo.

     Los totales van SEPARADOS por estado y no hay un gran total. Sumar
     disponible con retenido daría un número que no significa nada operativo:
     el retenido existe físicamente pero está fuera de la operación. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Existencias</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            @php
                $filtro = 'mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiquetaFiltro = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
            @endphp

            {{-- Totales del conjunto FILTRADO entero, no de la página visible. --}}
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                @forelse ($totales as $t)
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                        <div class="flex items-center justify-between">
                            <x-planta.badge :color="$t['estado']->color()">{{ $t['estado']->label() }}</x-planta.badge>
                            <span class="text-xs text-gray-400 dark:text-paper-500">{{ $t['buckets'] }} {{ $t['buckets'] === 1 ? 'saldo' : 'saldos' }}</span>
                        </div>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ $t['total'] }}</p>
                    </div>
                @empty
                    <div class="sm:col-span-3 rounded-xl bg-white p-4 text-sm text-gray-500 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:text-paper-400 dark:ring-ink-600">
                        No hay saldo que totalizar con esos filtros.
                    </div>
                @endforelse
            </div>

            <p class="mb-4 text-xs text-gray-500 dark:text-paper-400">
                Cada estado se totaliza por separado a propósito: solo el saldo <strong>disponible</strong>
                puede trasladarse o utilizarse. No existe un total que los sume.
            </p>

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="{{ $etiquetaFiltro }}">Insumo</label>
                    <select name="insumo" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($insumos as $i)
                            <option value="{{ $i->id }}" @selected(request('insumo') == $i->id)>{{ $i->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Tipo de insumo</label>
                    <select name="tipo" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($tipos as $t)
                            <option value="{{ $t->value }}" @selected(request('tipo') === $t->value)>{{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Lote</label>
                    <select name="lote" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($lotes as $l)
                            <option value="{{ $l->id }}" @selected(request('lote') == $l->id)>{{ $l->codigo_interno }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Ubicación</label>
                    <select name="ubicacion" class="{{ $filtro }}">
                        <option value="">Todas</option>
                        @foreach ($ubicaciones as $u)
                            <option value="{{ $u->id }}" @selected(request('ubicacion') == $u->id)>{{ $u->codigo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Estado</label>
                    <select name="estado" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($estados as $e)
                            <option value="{{ $e->value }}" @selected(request('estado') === $e->value)>{{ $e->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Tránsito</label>
                    <select name="transito" class="{{ $filtro }}">
                        <option value="">Todo</option>
                        <option value="si" @selected(request('transito') === 'si')>Solo en tránsito</option>
                        <option value="no" @selected(request('transito') === 'no')>Solo en ubicación</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Saldo</label>
                    <select name="saldo" class="{{ $filtro }}">
                        <option value="con" @selected(request('saldo') !== 'todos')>Con saldo</option>
                        <option value="todos" @selected(request('saldo') === 'todos')>Incluir saldo cero</option>
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-ink-600 dark:hover:bg-ink-500">Filtrar</button>
                <a href="{{ route('planta.existencias.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
            </form>

            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Insumo</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Lote</th>
                            <th class="px-4 py-3">Ubicación</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Traslado</th>
                            <th class="px-4 py-3 text-right">Cantidad</th>
                            <th class="px-4 py-3">Unidad</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($existencias as $existencia)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ $existencia->insumo?->nombre ?? '—' }}</span>
                                    <span class="ms-1 text-xs text-gray-400 dark:text-paper-500">{{ $existencia->insumo?->codigo }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($existencia->insumo)
                                        <x-planta.badge :color="$existencia->insumo->tipo->color()">{{ $existencia->insumo->tipo->label() }}</x-planta.badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{-- El lote genérico es un tecnicismo del motor: existe para que la
                                         clave del bucket nunca tenga un nulo. Enseñar su código al
                                         operario no aporta nada. --}}
                                    @if ($existencia->lote?->es_generico)
                                        <span class="text-gray-400 dark:text-paper-500">Sin lote</span>
                                    @else
                                        {{ $existencia->lote?->codigo_interno ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $existencia->ubicacion?->codigo ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$existencia->estado->color()">{{ $existencia->estado->label() }}</x-planta.badge>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($existencia->planta_traslado_id > 0)
                                        @can('planta.traslados.ver')
                                            <a href="{{ route('planta.traslados.show', $existencia->planta_traslado_id) }}"
                                               class="text-indigo-600 hover:underline dark:text-indigo-300">#{{ $existencia->traslado?->numero ?? $existencia->planta_traslado_id }}</a>
                                        @else
                                            #{{ $existencia->traslado?->numero ?? $existencia->planta_traslado_id }}
                                        @endcan
                                        @if ($existencia->traslado)
                                            <span class="ms-1 text-[11px] text-gray-400 dark:text-paper-500">{{ $existencia->traslado->estado->label() }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400 dark:text-paper-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $existencia->cantidad }}</td>
                                <td class="px-4 py-3">{{ $existencia->insumo?->unidad_base->abreviatura() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    No hay existencias con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $existencias->links() }}</div>
        </div>
    </div>
</x-app-layout>
