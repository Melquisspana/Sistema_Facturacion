<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $producto->nombre_es }}
                @unless ($producto->activo)
                    <span class="ms-2 inline-block rounded-full bg-gray-100 px-2.5 py-0.5 align-middle text-xs font-medium text-gray-600">Archivado</span>
                @endunless
            </h2>
            @can('exportaciones.gestionar')
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('productos.exportacion.edit', $producto) }}"
                       class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Editar</a>
                    <form method="POST" action="{{ route('productos.exportacion.toggle-activo', $producto) }}">
                        @csrf @method('PATCH')
                        <button class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">
                            {{ $producto->activo ? 'Archivar producto' : 'Reactivar producto' }}
                        </button>
                    </form>
                </div>
            @endcan
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

            @unless ($producto->activo)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                    Este producto está <strong>archivado</strong>: no aparece al armar listas de empaque nuevas. Sus precios de
                    cliente y su presencia en listas anteriores se conservan intactos, y reactivarlo lo devuelve al catálogo sin
                    volver a cargar nada.
                </div>
            @endunless

            {{-- Datos de empaque --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Presentación y empaque</h3>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Nombre en inglés</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $producto->nombre_en }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Código</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $producto->codigo ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Empaque</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $producto->unidad ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Unidades por caja</dt>
                        <dd class="mt-0.5 tabular-nums text-gray-800">{{ number_format($producto->unidades_por_caja) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Gramos / onzas por unidad</dt>
                        <dd class="mt-0.5 tabular-nums text-gray-800">{{ number_format((float) $producto->gramos_por_unidad, 2) }} g · {{ number_format((float) $producto->onzas_por_unidad, 2) }} oz</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Peso por caja (kg)</dt>
                        <dd class="mt-0.5 tabular-nums text-gray-800">Neto {{ number_format((float) $producto->peso_neto_caja_kg, 2) }} · Bruto {{ number_format((float) $producto->peso_bruto_caja_kg, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Peso por caja (lb)</dt>
                        <dd class="mt-0.5 tabular-nums text-gray-800">Neto {{ number_format((float) $producto->peso_neto_caja_lb, 2) }} · Bruto {{ number_format((float) $producto->peso_bruto_caja_lb, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Precio base por caja</dt>
                        <dd class="mt-0.5 tabular-nums font-medium text-gray-800">
                            {{ $producto->precio_caja !== null ? '$'.number_format((float) $producto->precio_caja, 2) : '— sin precio base' }}
                        </dd>
                        @if ($producto->precioPorUnidad() !== null)
                            <dd class="text-xs text-gray-500">${{ number_format($producto->precioPorUnidad(), 2) }} por unidad</dd>
                        @endif
                    </div>
                </dl>
                <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500">
                    El precio base es solo de REFERENCIA. Al armar una lista manda el precio que tenga el cliente en su lista de
                    precios; el base se usa únicamente cuando ese cliente no tiene precio propio, y en ese caso la lista lo avisa.
                </p>
            </div>

            {{-- Clientes y precios especiales --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Clientes que lo compran</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Los precios se administran desde la ficha de cada cliente, en su pestaña de exportación.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="py-3 px-4">Cliente</th>
                                <th scope="col" class="py-3 px-4 text-right">Precio por caja</th>
                                <th scope="col" class="py-3 px-4 text-right">Precio por unidad</th>
                                <th scope="col" class="py-3 px-4 text-right">Diferencia vs. base</th>
                                <th scope="col" class="py-3 px-4">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($producto->asignaciones as $asignacion)
                                @php
                                    $base = $producto->precio_caja !== null ? (float) $producto->precio_caja : null;
                                    $diferencia = $base !== null ? (float) $asignacion->precio_caja - $base : null;
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4">
                                        @if ($asignacion->cliente?->cliente_id)
                                            <a href="{{ route('clientes.show', $asignacion->cliente->cliente_id) }}"
                                               class="font-medium text-indigo-600 hover:underline">{{ $asignacion->cliente->nombreLegal() }}</a>
                                        @else
                                            <span class="font-medium text-gray-800">{{ $asignacion->cliente?->nombre ?? 'Cliente eliminado' }}</span>
                                            <div class="text-xs text-amber-700">Sin cliente vinculado en el directorio</div>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-800">${{ number_format((float) $asignacion->precio_caja, 2) }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">
                                        {{ $asignacion->precioPorUnidad() !== null ? '$'.number_format($asignacion->precioPorUnidad(), 2) : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-right tabular-nums {{ $diferencia !== null && abs($diferencia) > 0.001 ? 'text-gray-800' : 'text-gray-400' }}">
                                        @if ($diferencia === null)
                                            —
                                        @elseif (abs($diferencia) < 0.001)
                                            igual al base
                                        @else
                                            {{ $diferencia > 0 ? '+' : '−' }}${{ number_format(abs($diferencia), 2) }}
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        @if ($asignacion->activo)
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Habilitado</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Deshabilitado</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-10 text-center text-gray-400">Ningún cliente tiene precio para este producto.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('productos.exportacion.index') }}" class="text-sm text-indigo-600 hover:underline">← Volver a productos de exportación</a>

                @can('exportaciones.gestionar')
                    @php
                        $referencias = $producto->asignaciones->count() + $itemsCount;
                    @endphp
                    @if ($referencias === 0)
                        <form method="POST" action="{{ route('productos.exportacion.destroy', $producto) }}"
                              onsubmit="return confirm('¿Eliminar este producto? No tiene precios de cliente ni aparece en ninguna lista, así que no se pierde histórico.');">
                            @csrf @method('DELETE')
                            <button class="text-sm text-red-600 hover:underline">Eliminar producto</button>
                        </form>
                    @else
                        <p class="text-xs text-gray-500">
                            No se puede eliminar: {{ $producto->asignaciones->count() }} precio(s) de cliente y aparece en {{ $itemsCount }} lista(s).
                            Usá <strong>Archivar producto</strong>.
                        </p>
                    @endif
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
