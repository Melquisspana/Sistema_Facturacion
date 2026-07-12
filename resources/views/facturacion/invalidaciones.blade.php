<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Invalidaciones</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
                Documentos <span class="font-semibold">aceptados por Hacienda</span> que se pueden invalidar (CCF y notas de crédito).
                Elegí un documento y entrá a su ficha para invalidarlo. Esta pantalla <span class="font-semibold">no invalida ni transmite</span> nada.
            </div>

            {{-- Filtros --}}
            <form method="GET" action="{{ route('facturacion.invalidaciones') }}" class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-4">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600">Número / cliente</label>
                        <input type="text" name="q" value="{{ $filtros['q'] }}" placeholder="Nº de control, interno o cliente…"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Tipo</label>
                        <select name="tipo_dte" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Todos</option>
                            <option value="03" @selected($filtros['tipo_dte'] === '03')>CCF (03)</option>
                            <option value="05" @selected($filtros['tipo_dte'] === '05')>Nota de crédito (05)</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Desde</label>
                            <input type="date" name="fecha_desde" value="{{ $filtros['fecha_desde'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600">Hasta</label>
                            <input type="date" name="fecha_hasta" value="{{ $filtros['fecha_hasta'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                    <a href="{{ route('facturacion.invalidaciones') }}" class="text-sm text-gray-500 hover:underline">Limpiar</a>
                </div>
            </form>

            {{-- Tabla --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Documento</th>
                                <th class="py-3 px-4">Tipo</th>
                                <th class="py-3 px-4">Cliente / sala</th>
                                <th class="py-3 px-4">Fecha</th>
                                <th class="py-3 px-4 text-right">Total</th>
                                <th class="py-3 px-4">Sello</th>
                                <th class="py-3 px-4 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dtes as $dte)
                                @php $conEvento = $dte->tieneEventoInvalidacion(); @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 font-mono text-gray-800">{{ $dte->numero_control ?: $dte->numero_interno }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ $dte->tipo_dte->label() }}</td>
                                    <td class="py-3 px-4">
                                        <div class="text-gray-800">{{ $dte->cliente->nombre ?? '—' }}</div>
                                        @if ($dte->clienteSucursal)<div class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</div>@endif
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ optional($dte->fecha_emision)->format('d/m/Y') }}</td>
                                    <td class="py-3 px-4 text-right text-gray-800">${{ number_format((float) $dte->total_pagar, 2) }}</td>
                                    <td class="py-3 px-4 text-xs font-mono text-gray-500">{{ $dte->sello_recepcion ? \Illuminate\Support\Str::limit($dte->sello_recepcion, 12, '…') : '—' }}</td>
                                    <td class="py-3 px-4 text-right">
                                        @if ($conEvento)
                                            <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700">Ya tiene evento</span>
                                        @endif
                                        <a href="{{ route('facturacion.show', $dte) }}#invalidacion"
                                           class="ms-1 inline-flex items-center rounded-md bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700">
                                            Invalidar
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-10 text-center text-gray-400">No hay documentos aceptados para invalidar con estos filtros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $dtes->links() }}</div>
        </div>
    </div>
</x-app-layout>
