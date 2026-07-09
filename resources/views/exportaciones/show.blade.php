<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Lista de empaque #{{ $exportacion->id }}
                <span class="ms-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium align-middle {{ $exportacion->estado === 'borrador' ? 'bg-gray-100 text-gray-700' : 'bg-green-100 text-green-700' }}">{{ ucfirst($exportacion->estado) }}</span>
            </h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('exportaciones.edit', $exportacion) }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Editar</a>
                <form method="POST" action="{{ route('exportaciones.duplicar', $exportacion) }}">
                    @csrf
                    <button class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200"
                            title="Crea una copia con los mismos productos (snapshot) y fecha de hoy">Duplicar</button>
                </form>
                @if ($exportacion->items->isNotEmpty())
                    <a href="{{ route('exportaciones.excel', $exportacion) }}"
                       class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                        Descargar Excel
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif
            @if (session('aviso_precios'))
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">{{ session('aviso_precios') }}</div>
            @endif

            {{-- Encabezado --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Exportador</dt>
                        <dd class="mt-0.5 font-medium text-gray-800">{{ $exportacion->exportador_nombre }}</dd>
                        <dd class="text-gray-500">{{ $exportacion->exportador_direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Cliente</dt>
                        <dd class="mt-0.5 font-medium text-gray-800">{{ $exportacion->cliente_nombre }}</dd>
                        <dd class="text-gray-500">{{ $exportacion->cliente_direccion ?? '—' }}</dd>
                    </div>
                    <div class="space-y-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-400 inline">Fecha:</dt>
                            <dd class="inline font-medium text-gray-800">{{ $exportacion->fecha->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-400 inline">Factura:</dt>
                            <dd class="inline text-gray-800">{{ $exportacion->factura ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-400 inline">FDA reg. number:</dt>
                            <dd class="inline text-gray-800">{{ $exportacion->fda_reg_number ?? '—' }}</dd>
                        </div>
                    </div>
                </dl>
                @if ($exportacion->observaciones)
                    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600">{{ $exportacion->observaciones }}</p>
                @endif
            </div>

            {{-- Productos --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4 text-right">Cajas</th>
                                <th class="py-3 px-4">Descripción</th>
                                <th class="py-3 px-4">Unidad</th>
                                <th class="py-3 px-4 text-right">Unid./caja</th>
                                <th class="py-3 px-4 text-right">Total unid.</th>
                                <th class="py-3 px-4 text-right">Precio caja</th>
                                <th class="py-3 px-4 text-right">Valor total</th>
                                <th class="py-3 px-4 text-right">Neto kg</th>
                                <th class="py-3 px-4 text-right">Bruto kg</th>
                                <th class="py-3 px-4 text-right">Neto lb</th>
                                <th class="py-3 px-4 text-right">Bruto lb</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($exportacion->items as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 text-right font-medium text-gray-800">{{ $item->cantidad_cajas }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800">{{ $item->nombre_es }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->nombre_en }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $item->unidad ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $item->unidades_por_caja }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($item->totalUnidades()) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">${{ number_format((float) $item->precio_caja, 2) }}</td>
                                    <td class="py-3 px-4 text-right font-medium text-gray-800">${{ number_format($item->valorTotal(), 2) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($item->pesoNetoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($item->pesoBrutoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($item->pesoNetoTotalLb(), 1) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($item->pesoBrutoTotalLb(), 1) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="py-10 text-center text-gray-400">Sin productos. <a href="{{ route('exportaciones.edit', $exportacion) }}" class="text-indigo-600 hover:underline">Agregalos editando la lista</a>.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($exportacion->items->isNotEmpty())
                            <tfoot>
                                <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                                    <td class="py-3 px-4 text-right">{{ $exportacion->totalCajas() }}</td>
                                    <td class="py-3 px-4" colspan="3">Totales</td>
                                    <td class="py-3 px-4 text-right">{{ number_format($exportacion->totalUnidades()) }}</td>
                                    <td class="py-3 px-4"></td>
                                    <td class="py-3 px-4 text-right">${{ number_format($exportacion->valorTotal(), 2) }}</td>
                                    <td class="py-3 px-4 text-right">{{ number_format($exportacion->pesoNetoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right">{{ number_format($exportacion->pesoBrutoTotalKg(), 1) }}</td>
                                    <td class="py-3 px-4 text-right">{{ number_format($exportacion->pesoNetoTotalLb(), 1) }}</td>
                                    <td class="py-3 px-4 text-right">{{ number_format($exportacion->pesoBrutoTotalLb(), 1) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            <div class="flex justify-between">
                <a href="{{ route('exportaciones.index') }}" class="text-sm text-indigo-600 hover:underline">← Volver al listado</a>
                <form method="POST" action="{{ route('exportaciones.destroy', $exportacion) }}"
                      onsubmit="return confirm('¿Eliminar esta lista de empaque? Esta acción no se puede deshacer.');">
                    @csrf @method('DELETE')
                    <button class="text-sm text-red-600 hover:underline">Eliminar lista de empaque</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
