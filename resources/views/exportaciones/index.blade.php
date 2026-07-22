<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Exportaciones / Listas de empaque</h2>
            <div class="flex gap-2">
                <a href="{{ route('exportaciones.productos.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Catálogo de productos</a>
                <a href="{{ route('exportaciones.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nueva lista de empaque</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <form method="GET" class="mb-4 flex flex-wrap items-center gap-3">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Buscar por cliente o factura…"
                       class="w-72 rounded-md border-gray-300 text-sm">
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="archivadas" value="1" @checked(request()->boolean('archivadas')) class="rounded border-gray-300">
                    Mostrar archivadas
                </label>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Buscar</button>
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">#</th>
                                <th class="py-3 px-4">Fecha</th>
                                <th class="py-3 px-4">Cliente</th>
                                <th class="py-3 px-4">Factura</th>
                                <th class="py-3 px-4">Estado</th>
                                <th class="py-3 px-4 text-center">Productos</th>
                                <th class="py-3 px-4">FEX</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($exportaciones as $exportacion)
                                <tr class="hover:bg-gray-50 {{ $exportacion->archivada ? 'opacity-60' : '' }}">
                                    <td class="py-3 px-4 text-gray-400">{{ $exportacion->id }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ $exportacion->fecha->format('d/m/Y') }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800">
                                        <a href="{{ route('exportaciones.show', $exportacion) }}" class="hover:text-indigo-600">{{ $exportacion->cliente_nombre }}</a>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $exportacion->factura ?? '—' }}</td>
                                    <td class="py-3 px-4">
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $exportacion->estado === 'borrador' ? 'bg-gray-100 text-gray-700' : 'bg-green-100 text-green-700' }}">{{ ucfirst($exportacion->estado) }}</span>
                                        @if ($exportacion->archivada)
                                            <span class="ms-1 inline-block rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-600"
                                                  title="Archivada el {{ optional($exportacion->archivada_en)->format('d/m/Y') }}">
                                                {{ $exportacion->esPruebaApitest() ? 'Prueba APITEST / Archivada' : 'Archivada' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="inline-flex items-center justify-center min-w-[1.75rem] rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $exportacion->items_count }}</span>
                                    </td>
                                    <td class="py-3 px-4">
                                        @if ($exportacion->dte_id)
                                            <span class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">FEX #{{ $exportacion->dte_id }}</span>
                                        @elseif (! $exportacion->cliente?->cliente_id)
                                            <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Sin vincular</span>
                                        @elseif ($exportacion->items_count === 0)
                                            <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Sin productos</span>
                                        @else
                                            <span class="inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">Lista para FEX</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('exportaciones.show', $exportacion) }}" class="text-indigo-600 hover:underline">Ver</a>
                                            @if ($exportacion->items_count > 0)
                                                <a href="{{ route('exportaciones.excel', $exportacion) }}" class="inline-flex items-center gap-1 rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                                                    Excel
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-10 text-center text-gray-400">No hay exportaciones todavía. <a href="{{ route('exportaciones.create') }}" class="text-indigo-600 hover:underline">Creá la primera</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($exportaciones->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $exportaciones->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
