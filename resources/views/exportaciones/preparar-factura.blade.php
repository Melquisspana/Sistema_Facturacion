<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Preparar factura de exportación — lista #{{ $exportacion->id }}
            </h2>
            <a href="{{ route('exportaciones.show', $exportacion) }}" class="text-sm text-indigo-600 hover:underline">← Volver a la lista</a>
        </div>
    </x-slot>

    @php
        $lineas = $exportacion->lineasFactura();
        // Texto tabulado para copiar (una fila por línea; se pega directo en una hoja de cálculo).
        $tabulado = collect($lineas)
            ->map(fn ($l) => $l['descripcion']."\t".$l['cantidad']."\t".number_format($l['precio_unitario'], 2, '.', '')."\t".number_format($l['total'], 2, '.', ''))
            ->implode("\n");
    @endphp

    <div class="py-8" x-data="{ copiado: false }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Aviso: es un ayudante en vivo, no un documento fiscal ni congelado --}}
            <div class="rounded-md bg-sky-50 border border-sky-200 p-3 text-sm text-sky-800">
                Esta es una <span class="font-semibold">ayuda interna</span> para copiar los datos y armar la factura de
                exportación a mano. <span class="font-semibold">No es un DTE</span>, no emite ni transmite nada.
                Se calcula <span class="font-semibold">en vivo</span> desde la lista de empaque: si editás la lista, esta
                preparación cambia. El congelado real llegará en una fase futura (borrador FEX guardado).
            </div>

            {{-- Advertencia si la lista NO está aprobada --}}
            @unless ($exportacion->estaAprobada())
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                    <span class="font-semibold">Ojo:</span> esta lista todavía <span class="font-semibold">no está aprobada</span>.
                    Podés preparar la factura igual, pero conviene que se revise/apruebe antes.
                    <form method="POST" action="{{ route('exportaciones.aprobar', $exportacion) }}" class="inline"
                          onsubmit="return confirm('¿Marcar la lista #{{ $exportacion->id }} como aprobada?');">
                        @csrf @method('PATCH')
                        <button class="ms-1 font-medium text-amber-900 underline hover:no-underline">Marcar como aprobada</button>
                    </form>
                </div>
            @endunless

            {{-- Datos de cabecera (referencia) --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 text-sm">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3">
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">Cliente</dt><dd class="mt-0.5 font-medium text-gray-800">{{ $exportacion->cliente_nombre }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">Fecha</dt><dd class="mt-0.5 text-gray-800">{{ $exportacion->fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-gray-400">Factura</dt><dd class="mt-0.5 text-gray-800">{{ $exportacion->factura ?? 'Pendiente' }}</dd></div>
                </dl>
            </div>

            {{-- Acciones --}}
            <div class="flex flex-wrap items-center gap-3">
                <button type="button"
                        @click="navigator.clipboard.writeText($refs.datos.value).then(() => { copiado = true; setTimeout(() => copiado = false, 2000); })"
                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 3.5A1.5 1.5 0 018.5 2h3.879a1.5 1.5 0 011.06.44l3.122 3.12A1.5 1.5 0 0117 6.622V12.5a1.5 1.5 0 01-1.5 1.5h-1v-3.379a3 3 0 00-.879-2.121L10.5 5.379A3 3 0 008.379 4.5H7v-1z"/><path d="M4.5 6A1.5 1.5 0 003 7.5v9A1.5 1.5 0 004.5 18h7a1.5 1.5 0 001.5-1.5v-5.879a1.5 1.5 0 00-.44-1.06L9.44 6.439A1.5 1.5 0 008.378 6H4.5z"/></svg>
                    <span x-show="!copiado">Copiar filas</span>
                    <span x-show="copiado" x-cloak>¡Copiado!</span>
                </button>
                <a href="{{ route('exportaciones.preparar-factura.excel', $exportacion) }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                    Descargar Excel
                </a>
                <span class="text-xs text-gray-400">El botón «Copiar filas» copia descripción, cantidad, precio y total separados por tabulaciones (para pegar en una hoja de cálculo).</span>
            </div>

            {{-- Textarea oculto con el contenido tabulado a copiar --}}
            <textarea x-ref="datos" class="sr-only" aria-hidden="true" tabindex="-1">{{ $tabulado }}</textarea>

            {{-- Tabla de líneas --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Descripción</th>
                                <th class="py-3 px-4 text-right">Cantidad</th>
                                <th class="py-3 px-4 text-right">Precio unitario</th>
                                <th class="py-3 px-4 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($lineas as $linea)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 text-gray-800">{{ $linea['descripcion'] }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format($linea['cantidad']) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">${{ number_format($linea['precio_unitario'], 2) }}</td>
                                    <td class="py-3 px-4 text-right font-medium text-gray-800">${{ number_format($linea['total'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                                <td class="py-3 px-4" colspan="3">Total general</td>
                                <td class="py-3 px-4 text-right">${{ number_format($exportacion->valorTotal(), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
