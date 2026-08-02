{{-- Catálogo de lotes: qué entró, cuándo y de quién.

     NO hay botón de «nuevo lote» y no es un olvido: los lotes nacen en las
     recepciones, que son el documento que los justifica. Tampoco hay «editar»:
     cambiar el insumo o el código interno de un lote con movimientos rompería la
     traza que el lote existe para dar.

     La única acción es retirar o reincorporar, que conserva saldo e historial.

     El lote genérico (GEN-<insumo>) no aparece jamás en esta lista: es un
     detalle interno del motor de inventario, no algo que alguien haya recibido. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Lotes</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            @php
                $filtro = 'mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiquetaFiltro = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
                $hoy = \Illuminate\Support\Carbon::today();
            @endphp

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="{{ $etiquetaFiltro }}">Buscar</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Código interno o del proveedor…"
                           class="{{ $filtro }} w-64">
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
                    <label class="{{ $etiquetaFiltro }}">Proveedor</label>
                    <select name="proveedor" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($proveedores as $p)
                            <option value="{{ $p->id }}" @selected(request('proveedor') == $p->id)>{{ $p->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Estado</label>
                    <select name="activo" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        <option value="1" @selected(request('activo') === '1')>Activos</option>
                        <option value="0" @selected(request('activo') === '0')>Retirados</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Vencimiento</label>
                    <select name="vencimiento" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        <option value="vencidos" @selected(request('vencimiento') === 'vencidos')>Vencidos</option>
                        <option value="por_vencer" @selected(request('vencimiento') === 'por_vencer')>Por vencer</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Días</label>
                    <input type="number" name="dias" min="1" max="365" value="{{ $dias }}"
                           title="Ventana del filtro «por vencer»" class="{{ $filtro }} w-24">
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-ink-600 dark:hover:bg-ink-500">Filtrar</button>
                <a href="{{ route('planta.lotes.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
            </form>

            <p class="mb-4 text-xs text-gray-500 dark:text-paper-400">
                Los lotes se crean solos al confirmar una recepción. Aquí se consultan y, si hace falta,
                se <strong>retiran</strong> de la operación: retirar no borra nada ni mueve inventario,
                solo impide que el lote se use en entradas nuevas.
            </p>

            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Código interno</th>
                            <th class="px-4 py-3">Código del proveedor</th>
                            <th class="px-4 py-3">Insumo</th>
                            <th class="px-4 py-3">Proveedor</th>
                            <th class="px-4 py-3">Recepción</th>
                            <th class="px-4 py-3">Vencimiento</th>
                            <th class="px-4 py-3 text-right">Movimientos</th>
                            <th class="px-4 py-3 text-center">Estado</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($lotes as $lote)
                            @php
                                $vencido = $lote->fecha_vencimiento !== null && $lote->fecha_vencimiento->lt($hoy);
                            @endphp
                            <tr class="text-gray-700 dark:text-paper-200 {{ $lote->activo ? '' : 'opacity-60' }}">
                                <td class="px-4 py-3 font-mono text-xs">
                                    <a href="{{ route('planta.lotes.show', $lote) }}"
                                       class="text-indigo-600 hover:underline dark:text-indigo-300">{{ $lote->codigo_interno }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $lote->codigo_proveedor ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ $lote->insumo?->nombre ?? '—' }}</span>
                                    <span class="ms-1 text-xs text-gray-400 dark:text-paper-500">{{ $lote->insumo?->codigo }}</span>
                                </td>
                                <td class="px-4 py-3">{{ $lote->proveedor?->nombre ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $lote->fecha_recepcion?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if ($lote->fecha_vencimiento)
                                        <span class="{{ $vencido ? 'font-semibold text-red-700 dark:text-red-300' : '' }}">
                                            {{ $lote->fecha_vencimiento->format('d/m/Y') }}
                                        </span>
                                        @if ($vencido)
                                            <span class="ms-1 text-[11px] text-red-600 dark:text-red-300">(vencido)</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400 dark:text-paper-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $lote->movimientos_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <x-planta.toggle-activo :activo="$lote->activo"
                                                            :accion="route('planta.lotes.toggle-activo', $lote)" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('planta.lotes.show', $lote) }}"
                                       class="text-indigo-600 hover:underline dark:text-indigo-300">Ver</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    No hay lotes con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $lotes->links() }}</div>
        </div>
    </div>
</x-app-layout>
