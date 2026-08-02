{{-- Historial del libro mayor: por qué el saldo es el que es.

     SOLO LECTURA ABSOLUTA. El mayor es append-only y no hay forma de editarlo
     desde ninguna parte del sistema, esta pantalla incluida.

     Las REVERSIONES se distinguen de los hechos físicos a simple vista: llevan
     su propio tipo (`reversion_*`), comparten distintivo de color y se marcan
     con una etiqueta. Un par destino->origen que compensa un traslado NO es un
     traslado, y confundirlos al leer el historial haría creer que la mercancía
     viajó dos veces. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Movimientos de inventario</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-planta.avisos />

            @php
                $filtro = 'mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
                $etiquetaFiltro = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
            @endphp

            <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="{{ $etiquetaFiltro }}">Desde</label>
                    <input type="date" name="desde" value="{{ request('desde') }}" class="{{ $filtro }}">
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Hasta</label>
                    <input type="date" name="hasta" value="{{ request('hasta') }}" class="{{ $filtro }}">
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
                    <label class="{{ $etiquetaFiltro }}">Tipo de insumo</label>
                    <select name="tipo_insumo" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($tiposInsumo as $t)
                            <option value="{{ $t->value }}" @selected(request('tipo_insumo') === $t->value)>{{ $t->label() }}</option>
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
                    <label class="{{ $etiquetaFiltro }}">Tipo de movimiento</label>
                    <select name="tipo" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($tiposMovimiento as $t)
                            <option value="{{ $t->value }}" @selected(request('tipo') === $t->value)>{{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Naturaleza</label>
                    <select name="naturaleza" class="{{ $filtro }}">
                        <option value="">Todo</option>
                        <option value="operativo" @selected(request('naturaleza') === 'operativo')>Solo hechos físicos</option>
                        <option value="reversion" @selected(request('naturaleza') === 'reversion')>Solo reversiones</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Documento</label>
                    <select name="documento" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($documentos as $d)
                            <option value="{{ $d['value'] }}" @selected(request('documento') === $d['value'])>{{ $d['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">N.º interno doc.</label>
                    <input type="number" name="documento_id" value="{{ request('documento_id') }}" class="{{ $filtro }} w-28">
                </div>
                <div>
                    <label class="{{ $etiquetaFiltro }}">Usuario</label>
                    <select name="usuario" class="{{ $filtro }}">
                        <option value="">Todos</option>
                        @foreach ($usuarios as $u)
                            <option value="{{ $u->id }}" @selected(request('usuario') == $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-ink-600 dark:hover:bg-ink-500">Filtrar</button>
                <a href="{{ route('planta.movimientos.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
            </form>

            <div class="overflow-x-auto bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-ink-600">
                    <thead class="bg-gray-50 dark:bg-ink-900">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-400">
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Insumo</th>
                            <th class="px-4 py-3">Lote</th>
                            <th class="px-4 py-3">Ubicación</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3 text-right">Cantidad</th>
                            <th class="px-4 py-3">Documento</th>
                            <th class="px-4 py-3">Usuario</th>
                            <th class="px-4 py-3">Grupo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        @forelse ($movimientos as $movimiento)
                            @php
                                $rutaDocumento = \App\Support\Planta\DocumentoOrigen::ruta($movimiento->documento_type);
                                $permisoDocumento = \App\Support\Planta\DocumentoOrigen::permiso($movimiento->documento_type);
                                $claveDocumento = ltrim((string) $movimiento->documento_type, '\\').'#'.$movimiento->documento_id;
                                $numeroDocumento = $numerosDocumento[$claveDocumento] ?? null;
                            @endphp
                            <tr class="text-gray-700 dark:text-paper-200">
                                <td class="px-4 py-3 whitespace-nowrap">{{ $movimiento->fecha_efectiva?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$movimiento->tipo->color()">{{ $movimiento->tipo->label() }}</x-planta.badge>
                                    @if ($movimiento->tipo->esReversion())
                                        <span class="ms-1 text-[11px] text-rose-600 dark:text-rose-300">(reversión)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ $movimiento->insumo?->nombre ?? '—' }}</span>
                                    <span class="ms-1 text-xs text-gray-400 dark:text-paper-500">{{ $movimiento->insumo?->codigo }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($movimiento->lote?->es_generico)
                                        <span class="text-gray-400 dark:text-paper-500">Sin lote</span>
                                    @else
                                        {{ $movimiento->lote?->codigo_interno ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $movimiento->ubicacion?->codigo ?? '—' }}
                                    @if ($movimiento->planta_traslado_id > 0)
                                        <span class="ms-1 text-[11px] text-gray-400 dark:text-paper-500">tras. #{{ $movimiento->planta_traslado_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-planta.badge :color="$movimiento->estado->color()">{{ $movimiento->estado->label() }}</x-planta.badge>
                                </td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums {{ $movimiento->esEntrada() ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">
                                    {{ $movimiento->esEntrada() ? '+' : '' }}{{ $movimiento->cantidad }}
                                    <span class="ms-1 text-[11px] font-normal text-gray-400 dark:text-paper-500">{{ $movimiento->unidad_base?->abreviatura() }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{-- Mapa explícito: lo que no tiene pantalla se muestra como
                                         texto y no rompe la fila. --}}
                                    @if ($rutaDocumento && $permisoDocumento && auth()->user()?->can($permisoDocumento))
                                        <a href="{{ route($rutaDocumento, $movimiento->documento_id) }}"
                                           class="text-indigo-600 hover:underline dark:text-indigo-300">
                                            {{ \App\Support\Planta\DocumentoOrigen::etiqueta($movimiento->documento_type) }}
                                            @if ($numeroDocumento) #{{ $numeroDocumento }} @endif
                                        </a>
                                    @else
                                        <span>
                                            {{ \App\Support\Planta\DocumentoOrigen::etiqueta($movimiento->documento_type) }}
                                            @if ($numeroDocumento) #{{ $numeroDocumento }} @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $movimiento->user?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($movimiento->grupo_uuid)
                                        <span class="font-mono text-[11px] text-gray-400 dark:text-paper-500"
                                              title="{{ $movimiento->grupo_uuid }}">{{ substr($movimiento->grupo_uuid, 0, 8) }}</span>
                                    @else
                                        <span class="text-gray-400 dark:text-paper-500">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-paper-400">
                                    No hay movimientos con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $movimientos->links() }}</div>
        </div>
    </div>
</x-app-layout>
