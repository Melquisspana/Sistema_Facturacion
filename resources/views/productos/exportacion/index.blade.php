<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Productos</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                <x-productos.selector activo="exportacion" />

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                {{-- Filtros: mismo vocabulario que la pantalla de nacionales (buscar +
                     estado + limpiar), para que cambiar de pestaña no obligue a aprender
                     otro formulario. --}}
                <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
                    <div class="flex-1 min-w-48">
                        <x-input-label for="q" value="Buscar (nombre en español o inglés, código, empaque)" />
                        <x-text-input id="q" name="q" type="text" class="mt-1 block w-full"
                                      :value="$filtros['q']" placeholder="Escribe para buscar…" />
                    </div>
                    <div>
                        <x-input-label for="activo" value="Estado" />
                        <select id="activo" name="activo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="1" @selected($filtros['activo'] === '1')>Activos ({{ $totales['activos'] }})</option>
                            <option value="0" @selected($filtros['activo'] === '0')>Archivados ({{ $totales['inactivos'] }})</option>
                            <option value="" @selected($filtros['activo'] === '')>Todos</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('productos.exportacion.index') }}" class="px-4 py-2 text-sm text-gray-500 hover:underline self-center">Limpiar</a>
                    </div>
                </form>

                @can('exportaciones.gestionar')
                    <div class="mb-4 flex flex-wrap gap-2">
                        <a href="{{ route('productos.exportacion.create') }}"
                           class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Nuevo producto de exportación
                        </a>
                        <a href="{{ route('productos.exportacion.importar') }}"
                           class="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">
                            Importar desde Excel
                        </a>
                    </div>
                @endcan

                {{-- La tabla es ancha (empaque + pesos en dos sistemas de unidades): se
                     desplaza DENTRO de su contenedor, nunca la página. --}}
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th scope="col" class="py-3 px-4">Producto</th>
                                <th scope="col" class="py-3 px-4">Empaque</th>
                                <th scope="col" class="py-3 px-4 text-right">Unid./caja</th>
                                <th scope="col" class="py-3 px-4 text-right">Precio base</th>
                                <th scope="col" class="py-3 px-4 text-right">Neto / bruto kg</th>
                                <th scope="col" class="py-3 px-4">Clientes</th>
                                <th scope="col" class="py-3 px-4">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($productos as $producto)
                                <tr class="hover:bg-gray-50 {{ $producto->activo ? '' : 'opacity-70' }}">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('productos.exportacion.show', $producto) }}"
                                           class="font-medium text-indigo-600 hover:underline">{{ $producto->nombre_es }}</a>
                                        <div class="text-xs text-gray-500">{{ $producto->nombre_en }}</div>
                                        @if (filled($producto->codigo))
                                            <div class="text-xs text-gray-400">Código {{ $producto->codigo }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $producto->unidad ?: '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 tabular-nums">{{ number_format($producto->unidades_por_caja) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600 tabular-nums">
                                        {{ $producto->precio_caja !== null ? '$'.number_format((float) $producto->precio_caja, 2) : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-600 tabular-nums">
                                        {{ number_format((float) $producto->peso_neto_caja_kg, 2) }} / {{ number_format((float) $producto->peso_bruto_caja_kg, 2) }}
                                    </td>
                                    <td class="py-3 px-4">
                                        @if ($producto->asignaciones_activas_count > 0)
                                            <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                                {{ $producto->asignaciones_activas_count }}
                                            </span>
                                            <span class="ms-1 text-xs text-gray-500">
                                                {{ $producto->asignaciones->take(2)->map(fn ($a) => $a->cliente?->nombre)->filter()->implode(', ') }}@if ($producto->asignaciones_activas_count > 2)…@endif
                                            </span>
                                        @else
                                            <span class="text-xs text-gray-400">Sin precios de cliente</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        @if ($producto->activo)
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Activo</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Archivado</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-10 text-center text-gray-400">
                                        @if ($filtros['q'] !== '')
                                            Ningún producto de exportación coincide con «{{ $filtros['q'] }}».
                                        @else
                                            Todavía no hay productos de exportación.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $productos->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
