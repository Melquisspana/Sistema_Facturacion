<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reporte contadora</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Aviso de alcance --}}
            <div class="rounded-md bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                Este reporte solo incluye documentos generados en <span class="font-semibold">este sistema</span>.
                No incluye documentos hechos en Conta Portable salvo que luego se importen. Por defecto muestra solo
                el ambiente de <span class="font-semibold">producción (01)</span> y documentos
                <span class="font-semibold">aceptados</span> por Hacienda (excluye pruebas, mock y simulaciones).
            </div>

            {{-- Filtros --}}
            <form method="GET" action="{{ route('facturacion.reporte-contadora') }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha desde</label>
                        <input type="date" name="fecha_desde" value="{{ $filtros['fecha_desde'] }}"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha hasta</label>
                        <input type="date" name="fecha_hasta" value="{{ $filtros['fecha_hasta'] }}"
                               class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Tipo de documento</label>
                        <select name="tipo_documento" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="todos" @selected($filtros['tipo'] === 'todos')>Todos</option>
                            @foreach ($tipos as $codigo => $nombre)
                                <option value="{{ $codigo }}" @selected($filtros['tipo'] === $codigo)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Estado</label>
                        <select name="estado" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="aceptado" @selected($filtros['estado'] === 'aceptado')>Aceptado (real)</option>
                            <option value="todos" @selected($filtros['estado'] === 'todos')>Todos</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Ambiente</label>
                        <select name="ambiente" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="01" @selected($filtros['ambiente'] === '01')>Producción (01)</option>
                            <option value="00" @selected($filtros['ambiente'] === '00')>Pruebas (00)</option>
                            <option value="todos" @selected($filtros['ambiente'] === 'todos')>Todos</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Aplicar filtros</button>
                    <a href="{{ route('facturacion.reporte-contadora.exportar', request()->query()) }}"
                       class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                        Descargar Excel
                    </a>
                    {{-- Pendiente: el envío real a contabilidad se hace por el flujo de correo del documento. --}}
                    <button type="button" disabled title="Pendiente: el envío a contabilidad se hace desde el correo de cada documento."
                            class="inline-flex items-center gap-1.5 rounded-md bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed">
                        Enviar a contabilidad (pendiente)
                    </button>
                </div>
            </form>

            {{-- Vista previa --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 text-xs text-gray-500">
                    Vista previa (hasta 500 filas). El Excel exporta todo el rango filtrado.
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-3">Fecha</th>
                                <th class="py-3 px-3">Tipo</th>
                                <th class="py-3 px-3">Cliente</th>
                                <th class="py-3 px-3">NIT</th>
                                <th class="py-3 px-3">Número de control</th>
                                <th class="py-3 px-3">Sello</th>
                                <th class="py-3 px-3">Estado</th>
                                <th class="py-3 px-3 text-right">Gravado</th>
                                <th class="py-3 px-3 text-right">IVA</th>
                                <th class="py-3 px-3 text-right">Retención</th>
                                <th class="py-3 px-3 text-right">Total</th>
                                <th class="py-3 px-3 text-center">Correo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dtes as $dte)
                                @php($enviado = in_array($dte->ultimo_envio_estado ?? null, ['enviado', 'simulado'], true))
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 px-3 text-gray-600">{{ optional($dte->fecha_emision)->format('d/m/Y') }}</td>
                                    <td class="py-2 px-3 text-gray-600">{{ $dte->tipo_dte?->label() }}</td>
                                    <td class="py-2 px-3 text-gray-800">{{ $dte->cliente?->nombre ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600">{{ $dte->cliente?->num_documento ?? '—' }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-600">{{ $dte->numero_control ?? '—' }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-500">{{ $dte->sello_recepcion ? \Illuminate\Support\Str::limit($dte->sello_recepcion, 14) : '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600">{{ $dte->estado?->label() }}</td>
                                    <td class="py-2 px-3 text-right text-gray-600">{{ number_format((float) $dte->total_gravado, 2) }}</td>
                                    <td class="py-2 px-3 text-right text-gray-600">{{ number_format((float) $dte->iva, 2) }}</td>
                                    <td class="py-2 px-3 text-right text-gray-600">{{ number_format((float) $dte->iva_retenido, 2) }}</td>
                                    <td class="py-2 px-3 text-right font-medium text-gray-800">{{ number_format((float) $dte->total_pagar, 2) }}</td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $enviado ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $enviado ? 'Sí' : 'No' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="py-10 text-center text-gray-400">No hay documentos para los filtros elegidos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
