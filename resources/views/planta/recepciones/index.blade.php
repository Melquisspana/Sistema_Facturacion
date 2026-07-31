<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Recepciones de insumos</h2>
            @can('planta.recepciones.crear')
                <a href="{{ route('planta.recepciones.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva recepción</a>
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
                    <label class="{{ $etiquetaFiltro }}">Proveedor</label>
                    <select name="proveedor" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($proveedores as $proveedor)
                            <option value="{{ $proveedor->id }}" @selected(request('proveedor') == $proveedor->id)>{{ $proveedor->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Ubicación</label>
                    <select name="ubicacion" class="{{ $filtro }}">
                        <option value="">Todas</option>
                        @foreach ($ubicaciones as $ubicacion)
                            <option value="{{ $ubicacion->id }}" @selected(request('ubicacion') == $ubicacion->id)>{{ $ubicacion->codigo }}</option>
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
                        @foreach ($insumos as $insumo)
                            <option value="{{ $insumo->id }}" @selected(request('insumo') == $insumo->id)>{{ $insumo->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Creado por</label>
                    <select name="creado_por" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected(request('creado_por') == $usuario->id)>{{ $usuario->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-ink-600 dark:hover:bg-ink-500">Filtrar</button>
                <a href="{{ route('planta.recepciones.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
            </form>

            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Número</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Proveedor</th>
                            <th class="px-4 py-3">Ubicación</th>
                            <th class="px-4 py-3">Responsable</th>
                            <th class="px-4 py-3 text-right">Líneas</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Creado por</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($recepciones as $recepcion)
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3 font-medium">
                                    #{{ $recepcion->numero }}
                                    @if ($recepcion->esReversion())
                                        <span class="ms-1 text-[11px] text-rose-600 dark:text-rose-300">(reversión)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $recepcion->fecha->format('d/m/Y') }}</td>
                                <td class="px-4 py-3">{{ $recepcion->proveedor?->nombre ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $recepcion->ubicacion?->codigo ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $recepcion->responsable_nombre ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">{{ $recepcion->detalles_count }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$recepcion->estado->color()">{{ $recepcion->estado->label() }}</x-planta.badge>
                                </td>
                                <td class="px-4 py-3">{{ $recepcion->creadoPor?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('planta.recepciones.show', $recepcion) }}"
                                       class="text-indigo-600 hover:underline dark:text-indigo-300">Ver</a>
                                    @if ($recepcion->esEditable())
                                        @can('planta.recepciones.crear')
                                            <a href="{{ route('planta.recepciones.edit', $recepcion) }}"
                                               class="ms-3 text-indigo-600 hover:underline dark:text-indigo-300">Editar</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    No hay recepciones con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $recepciones->links() }}</div>
        </div>
    </div>
</x-app-layout>
