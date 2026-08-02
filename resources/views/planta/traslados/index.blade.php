<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Traslados</h2>
            @can('planta.traslados.crear')
                <a href="{{ route('planta.traslados.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo traslado</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            @if ($errors->any())
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                    <ul class="list-disc ps-5 space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @php
                $filtro = 'mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiquetaFiltro = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
            @endphp

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="{{ $etiquetaFiltro }}">Número</label>
                    <input type="number" name="numero" value="{{ request('numero') }}" class="{{ $filtro }} w-24">
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Desde</label>
                    <input type="date" name="desde" value="{{ request('desde') }}" class="{{ $filtro }}">
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Hasta</label>
                    <input type="date" name="hasta" value="{{ request('hasta') }}" class="{{ $filtro }}">
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Origen</label>
                    <select name="origen" class="{{ $filtro }}">
                        <option value="">Todas</option>
                        @foreach ($ubicaciones as $u)
                            <option value="{{ $u->id }}" @selected(request('origen') == $u->id)>{{ $u->codigo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Destino</label>
                    <select name="destino" class="{{ $filtro }}">
                        <option value="">Todas</option>
                        @foreach ($ubicaciones as $u)
                            <option value="{{ $u->id }}" @selected(request('destino') == $u->id)>{{ $u->codigo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Estado</label>
                    <select name="estado" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($estados as $estado)
                            <option value="{{ $estado->value }}" @selected(request('estado') === $estado->value)>{{ $estado->label() }}</option>
                        @endforeach
                    </select>
                </div>
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
                    <label class="{{ $etiquetaFiltro }}">Lote</label>
                    <select name="lote" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($lotes as $l)
                            <option value="{{ $l->id }}" @selected(request('lote') == $l->id)>{{ $l->codigo_interno }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-ink-600 dark:hover:bg-ink-500">Filtrar</button>
                <a href="{{ route('planta.traslados.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
            </form>

            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Número</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Origen</th>
                            <th class="px-4 py-3">Destino</th>
                            <th class="px-4 py-3 text-right">Líneas</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Responsable</th>
                            <th class="px-4 py-3">Enviado</th>
                            <th class="px-4 py-3">Días en tránsito</th>
                            <th class="px-4 py-3">Recibido</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($traslados as $traslado)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3 font-medium">
                                    #{{ $traslado->numero }}
                                    @if ($traslado->esReversion())
                                        <span class="ms-1 text-[11px] text-rose-600 dark:text-rose-300">(reversión)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $traslado->fecha->format('d/m/Y') }}</td>
                                <td class="px-4 py-3">{{ $traslado->origen?->codigo ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $traslado->destino?->codigo ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">{{ $traslado->detalles_count }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$traslado->estado->color()">{{ $traslado->estado->label() }}</x-planta.badge>
                                </td>
                                <td class="px-4 py-3">{{ $traslado->responsable_nombre ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $traslado->enviado_en?->format('d/m/Y H:i') ?? '—' }}</td>
                                {{-- Días en tránsito: SOLO para lo que está viajando ahora.
                                     Un traslado recibido, cancelado o reversado conserva su
                                     `enviado_en` como historia, y mostrarle una cifra de
                                     tránsito vigente diría que sigue en camino. El cálculo y
                                     los umbrales viven en PlantaDashboardQuery, que es el
                                     único sitio donde está esa regla: el panel de inicio usa
                                     exactamente los mismos. No cuesta ninguna consulta:
                                     `enviado_en` ya viene con la fila. --}}
                                <td class="px-4 py-3">
                                    @php
                                        $diasTransito = \App\Support\Planta\PlantaDashboardQuery::diasEnTransito($traslado);
                                        $sevTransito = \App\Support\Planta\PlantaDashboardQuery::severidadTransito($diasTransito);
                                    @endphp
                                    @if ($diasTransito === null)
                                        <span class="text-gray-400 dark:text-paper-500">—</span>
                                    @else
                                        <x-planta.badge :color="$sevTransito">
                                            {{ $diasTransito }} {{ $diasTransito === 1 ? 'día' : 'días' }}
                                        </x-planta.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $traslado->recibido_en?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('planta.traslados.show', $traslado) }}"
                                       class="text-indigo-600 hover:underline dark:text-indigo-300">Ver</a>
                                    @if ($traslado->esEditable())
                                        @can('planta.traslados.crear')
                                            <a href="{{ route('planta.traslados.edit', $traslado) }}"
                                               class="ms-3 text-indigo-600 hover:underline dark:text-indigo-300">Editar</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    No hay traslados con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $traslados->links() }}</div>
        </div>
    </div>
</x-app-layout>
