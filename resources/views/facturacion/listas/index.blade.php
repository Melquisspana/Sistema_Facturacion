@php
    $chipOn = 'rounded-full bg-gray-800 px-3 py-1 text-xs font-medium text-white';
    $chipOff = 'rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 hover:bg-gray-200';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Listas de empaque</h2>
            @can('exportaciones.gestionar')
                <a href="{{ route('facturacion.listas.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva lista de empaque</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                @if ($pendientesRevision > 0 && ! $soloRevision)
                    <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                        Hay <strong>{{ $pendientesRevision }}</strong> lista(s) heredada(s) del flujo anterior cuyo estado no se pudo
                        traducir con certeza. No se cambió ninguna, y están <strong>congeladas</strong> —no se editan, ni se
                        facturan, ni se finalizan— hasta que un administrador las clasifique.
                        <a href="{{ route('facturacion.listas.index', ['revision' => 1, 'estado' => 'todas']) }}" class="font-medium underline">Revisarlas</a>.
                    </div>
                @endif

                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <a href="{{ route('facturacion.listas.index', array_filter(['estado' => 'abiertas', 'q' => request('q')])) }}"
                       class="{{ $estado === 'abiertas' ? $chipOn : $chipOff }}">Abiertas</a>
                    <a href="{{ route('facturacion.listas.index', array_filter(['estado' => 'finalizadas', 'q' => request('q')])) }}"
                       class="{{ $estado === 'finalizadas' ? $chipOn : $chipOff }}">Finalizadas</a>
                    @if ($archivadas > 0)
                        {{-- Solo aparece si de verdad hay listas archivadas. El flujo nuevo no
                             archiva nada; el chip existe para poder llegar a las que dejó el
                             flujo anterior sin resucitarlas en el listado normal. --}}
                        <a href="{{ route('facturacion.listas.index', array_filter(['estado' => 'archivadas', 'q' => request('q')])) }}"
                           class="{{ $estado === 'archivadas' ? $chipOn : $chipOff }}">Archivadas ({{ $archivadas }})</a>
                    @endif
                    <a href="{{ route('facturacion.listas.index', array_filter(['estado' => 'todas', 'q' => request('q')])) }}"
                       class="{{ $estado === 'todas' ? $chipOn : $chipOff }}">Todas</a>

                    <form method="GET" class="ms-auto flex flex-wrap items-center gap-2">
                        @if ($estado !== 'abiertas')
                            <input type="hidden" name="estado" value="{{ $estado }}">
                        @endif
                        @if ($soloRevision)
                            <input type="hidden" name="revision" value="1">
                        @endif
                        <label class="sr-only" for="q">Buscar por cliente o factura</label>
                        <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Cliente o factura…"
                               class="w-56 rounded-md border-gray-300 text-sm">
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Buscar</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Listas de empaque</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="py-3 px-4">Lista</th>
                                <th scope="col" class="py-3 px-4">Cliente</th>
                                <th scope="col" class="py-3 px-4">Fecha</th>
                                <th scope="col" class="py-3 px-4">Factura(s)</th>
                                <th scope="col" class="py-3 px-4 text-right">Productos</th>
                                <th scope="col" class="py-3 px-4">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($listas as $lista)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('facturacion.listas.show', $lista) }}"
                                           class="font-medium text-indigo-600 hover:underline">#{{ $lista->id }}</a>
                                        @if ($lista->archivada)
                                            <span class="ms-1 inline-flex rounded-full bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600"
                                                  title="Archivada por el flujo anterior el {{ $lista->archivada_en?->format('d/m/Y') ?? '—' }}. Se conserva; no aparece en el listado normal.">
                                                {{ $lista->esPruebaApitest() ? 'Prueba APITEST / Archivada' : 'Archivada' }}
                                            </span>
                                        @elseif ($lista->esPruebaApitest())
                                            <span class="ms-1 inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">Prueba</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-gray-800">{{ $lista->cliente?->nombreLegal() ?? $lista->cliente_nombre }}</td>
                                    <td class="py-3 px-4 tabular-nums text-gray-600">{{ $lista->fecha?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-3 px-4 font-mono text-xs text-gray-600">{{ $lista->textoFactura() ?: '—' }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums text-gray-600">{{ $lista->items_count }}</td>
                                    <td class="py-3 px-4">
                                        @if ($lista->estaFinalizada())
                                            <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Finalizada</span>
                                        @elseif ($lista->requiereRevision())
                                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700"
                                                  title="Estado «{{ $lista->estadoOriginalHeredado() }}» del flujo anterior: se conserva y la lista queda congelada hasta que un administrador la clasifique.">Requiere revisión</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Borrador</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-400">
                                        @if (request()->filled('q'))
                                            Ninguna lista coincide con «{{ request('q') }}».
                                        @else
                                            Todavía no hay listas de empaque.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $listas->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
