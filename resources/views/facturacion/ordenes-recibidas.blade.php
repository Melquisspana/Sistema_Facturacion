{{-- Órdenes de compra de Calleja que llegaron por correo (ordenes:sincronizar).
     Sirve para ver las que llegaron y todavía no tienen CCF. Solo lectura. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Órdenes recibidas</h1>
        </div>
    </x-slot>

    @php($hayFiltro = $ver !== 'sin_facturar' || filled($mes))

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-600">
                Órdenes de compra que llegaron a la etiqueta de Gmail. Cuando un CCF lleva el mismo número de orden, la orden queda como facturada.
            </p>

            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    Revisá estos filtros: {{ $errors->first() }}
                </div>
            @endif

            <form method="GET" action="{{ route('facturacion.ordenes-recibidas.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="ver" class="block text-sm font-medium text-gray-700">Ver</label>
                    <select id="ver" name="ver" class="mt-1 block min-h-11 rounded-md border-gray-300 shadow-sm text-sm">
                        <option value="sin_facturar" @selected($ver === 'sin_facturar')>Sin facturar</option>
                        <option value="todas" @selected($ver === 'todas')>Todas</option>
                    </select>
                </div>
                <div>
                    <label for="mes" class="block text-sm font-medium text-gray-700">Mes (opcional)</label>
                    <input id="mes" name="mes" type="month" value="{{ $mes }}"
                           class="mt-1 block min-h-11 rounded-md border-gray-300 shadow-sm text-sm">
                </div>
                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Filtrar</button>
                    @if ($hayFiltro)
                        <a href="{{ route('facturacion.ordenes-recibidas.index') }}" class="text-sm text-indigo-600 hover:underline">Limpiar</a>
                    @endif
                </div>
            </form>

            <div class="rounded-lg border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Número</th>
                                <th scope="col" class="px-4 py-3">Sala</th>
                                <th scope="col" class="px-4 py-3">Creación</th>
                                <th scope="col" class="px-4 py-3">Despacho</th>
                                <th scope="col" class="px-4 py-3 text-right">Total</th>
                                <th scope="col" class="px-4 py-3">Facturada</th>
                                <th scope="col" class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($ordenes as $orden)
                                @php($ccf = $orden->numero !== null ? ($ccfs[$orden->numero] ?? null) : null)
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono whitespace-nowrap">
                                        @if ($orden->numero !== null)
                                            {{ $orden->numero }}
                                        @else
                                            <span class="font-sans text-amber-800">Sin leer</span>
                                            <span class="block font-sans text-xs text-gray-500">No se pudo leer el número: abrí el PDF.</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($orden->sala_codigo)
                                            <span class="font-mono">{{ $orden->sala_codigo }}</span>
                                            <span class="block text-xs text-gray-500">{{ $orden->clienteSucursal?->nombre ?? \App\Support\Sala::nombre($orden->sala_codigo) ?? 'Sala sin registrar' }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $orden->fecha_creacion?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $orden->fecha_despacho?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $orden->total !== null ? '$'.number_format((float) $orden->total, 2) : '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if ($ccf)
                                            <a href="{{ route('facturacion.show', $ccf) }}" class="font-mono text-xs text-indigo-600 hover:underline">
                                                {{ $ccf->numero_control ?? $ccf->numero_interno ?? ('#'.$ccf->id) }}</a>
                                            @if ($ccf->estado === \App\Enums\EstadoDte::Borrador)
                                                <span class="block text-xs text-gray-500">Borrador</span>
                                            @endif
                                        @elseif ($orden->numero !== null)
                                            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800">Sin facturar</span>
                                        @else
                                            <span class="text-gray-500">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('facturacion.ordenes-recibidas.pdf', $orden) }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">Ver PDF</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-gray-500">
                                        @if ($hayFiltro)
                                            Nada coincide con estos filtros.
                                            <a href="{{ route('facturacion.ordenes-recibidas.index') }}" class="text-indigo-600 hover:underline">Limpiar filtros</a>
                                        @else
                                            No hay órdenes sin facturar.
                                            <a href="{{ route('facturacion.ordenes-recibidas.index', ['ver' => 'todas']) }}" class="text-indigo-600 hover:underline">Ver todas</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($ordenes->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3">{{ $ordenes->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
