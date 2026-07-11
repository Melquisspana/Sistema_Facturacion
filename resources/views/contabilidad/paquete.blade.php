<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Paquete mensual para contabilidad</h2>
    </x-slot>

    @php
        $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
                  7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                Este paquete es <span class="font-semibold">interno</span> para enviar a contabilidad. La contadora no
                entra al sistema: vos generás y descargás el ZIP y se lo mandás por fuera. No se envía ningún correo.
            </div>

            {{-- Filtros --}}
            <form method="GET" action="{{ route('contabilidad.paquete') }}" class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">Periodo</h3>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Mes</label>
                        <select name="mes" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            @foreach ($meses as $n => $nombre)
                                <option value="{{ $n }}" @selected($rango['mes'] === $n)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Año</label>
                        <input type="number" name="anio" value="{{ $rango['anio'] }}" min="2020" max="2100" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha desde (opcional)</label>
                        <input type="date" name="fecha_desde" value="{{ request('fecha_desde') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha hasta (opcional)</label>
                        <input type="date" name="fecha_hasta" value="{{ request('fecha_hasta') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-400">Si indicás fechas, tienen prioridad sobre mes/año.</p>
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="incluir_compras" value="1" @checked($incluirCompras) class="rounded border-gray-300">
                        Incluir compras (recibidos)
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="incluir_ventas" value="1" @checked($incluirVentas) class="rounded border-gray-300">
                        Incluir ventas (emitidos)
                    </label>
                    <button class="ms-auto rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Ver resumen</button>
                </div>
            </form>

            {{-- Resumen --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Resumen del periodo</h3>
                    <span class="text-xs text-gray-500">Rango: {{ $rango['desde'] }} a {{ $rango['hasta'] }} ({{ $rango['etiqueta'] }})</span>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-sky-50 ring-1 ring-sky-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-sky-600">Compras (recibidos)</p>
                        <p class="mt-1 text-2xl font-semibold text-sky-800">{{ number_format($resumen['compras_cantidad']) }} <span class="text-sm font-normal text-sky-600">documentos</span></p>
                        <p class="text-sm text-sky-700">Total ${{ number_format($resumen['compras_total'], 2) }}</p>
                    </div>
                    <div class="rounded-lg bg-green-50 ring-1 ring-green-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-green-600">Ventas (emitidos)</p>
                        <p class="mt-1 text-2xl font-semibold text-green-800">{{ number_format($resumen['ventas_cantidad']) }} <span class="text-sm font-normal text-green-600">documentos</span></p>
                        <p class="text-sm text-green-700">Total ${{ number_format($resumen['ventas_total'], 2) }}</p>
                    </div>
                </div>
            </div>

            {{-- Generar --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Generar paquete</h3>
                <p class="mt-1 text-xs text-gray-500">
                    Genera <code>documentos_contabilidad_{{ $rango['etiqueta'] }}.zip</code> con los Excel de compras y ventas,
                    y los PDF/JSON de compras ya guardados. Los adjuntos de <span class="font-medium">ventas</span> (emitidos)
                    se agregarán en una fase posterior.
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('contabilidad.paquete.generar') }}">
                        @csrf
                        <input type="hidden" name="mes" value="{{ $rango['mes'] }}">
                        <input type="hidden" name="anio" value="{{ $rango['anio'] }}">
                        <input type="hidden" name="fecha_desde" value="{{ request('fecha_desde') }}">
                        <input type="hidden" name="fecha_hasta" value="{{ request('fecha_hasta') }}">
                        <input type="hidden" name="incluir_compras" value="{{ $incluirCompras ? 1 : 0 }}">
                        <input type="hidden" name="incluir_ventas" value="{{ $incluirVentas ? 1 : 0 }}">
                        <button class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                            Generar ZIP
                        </button>
                    </form>
                    {{-- Envío directo a contabilidad, con confirmación por frase exacta. --}}
                    @if ($puedeEnviar)
                        <button type="button" onclick="document.getElementById('modal-enviar-contabilidad').classList.remove('hidden')"
                                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95 28.897 28.897 0 0015.293-7.155.75.75 0 000-1.114A28.897 28.897 0 003.105 2.289z"/></svg>
                            Enviar a contabilidad
                        </button>
                    @else
                        <button type="button" disabled
                                title="{{ $correoContabilidad === null ? 'Falta un correo de contabilidad válido (Configuración > Contabilidad).' : 'No hay documentos en el rango para las fuentes incluidas.' }}"
                                class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed">
                            Enviar a contabilidad
                        </button>
                    @endif
                </div>
                @unless ($puedeEnviar)
                    <p class="mt-2 text-xs text-gray-400">
                        @if ($correoContabilidad === null)
                            Para habilitar el envío, configurá un correo de contabilidad válido en
                            <a href="{{ route('configuracion.contabilidad.edit') }}" class="text-indigo-600 hover:underline">Configuración &gt; Contabilidad</a>.
                        @else
                            El envío se habilita cuando hay documentos en el rango para las fuentes incluidas.
                        @endif
                    </p>
                @endunless
            </div>

            {{-- Modal de confirmación de envío a contabilidad --}}
            @if ($puedeEnviar)
                <div id="modal-enviar-contabilidad" class="hidden fixed inset-0 z-50 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <div class="fixed inset-0 bg-gray-900/50" onclick="document.getElementById('modal-enviar-contabilidad').classList.add('hidden')"></div>
                        <div class="relative bg-white rounded-xl shadow-xl ring-1 ring-gray-200 w-full max-w-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900">Confirmar envío a contabilidad</h3>
                            <p class="mt-1 text-sm text-gray-500">Revisá los datos. Se enviará un solo correo con el ZIP adjunto. No cambia estados ni documentos.</p>

                            <dl class="mt-4 divide-y divide-gray-100 text-sm">
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Correo destino</dt><dd class="font-medium text-gray-900">{{ $correoContabilidad }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Rango</dt><dd class="font-medium text-gray-900">{{ $rango['desde'] }} a {{ $rango['hasta'] }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Incluir compras</dt><dd class="font-medium text-gray-900">{{ $incluirCompras ? 'Sí' : 'No' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Incluir ventas</dt><dd class="font-medium text-gray-900">{{ $incluirVentas ? 'Sí' : 'No' }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Compras</dt><dd class="font-medium text-gray-900">{{ number_format($resumen['compras_cantidad']) }} docs — ${{ number_format($resumen['compras_total'], 2) }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Ventas</dt><dd class="font-medium text-gray-900">{{ number_format($resumen['ventas_cantidad']) }} docs — ${{ number_format($resumen['ventas_total'], 2) }}</dd></div>
                                <div class="flex justify-between py-1.5"><dt class="text-gray-500">Archivo ZIP</dt><dd class="font-medium text-gray-900">documentos_contabilidad_{{ $rango['etiqueta'] }}.zip</dd></div>
                            </dl>

                            <form method="POST" action="{{ route('contabilidad.paquete.enviar') }}" class="mt-4"
                                  onsubmit="return this.frase.value.trim() === @json($fraseEnvio) || (alert('Escribí la frase exacta: {{ $fraseEnvio }}'), false);">
                                @csrf
                                <input type="hidden" name="mes" value="{{ $rango['mes'] }}">
                                <input type="hidden" name="anio" value="{{ $rango['anio'] }}">
                                <input type="hidden" name="fecha_desde" value="{{ request('fecha_desde') }}">
                                <input type="hidden" name="fecha_hasta" value="{{ request('fecha_hasta') }}">
                                <input type="hidden" name="incluir_compras" value="{{ $incluirCompras ? 1 : 0 }}">
                                <input type="hidden" name="incluir_ventas" value="{{ $incluirVentas ? 1 : 0 }}">
                                <label class="block text-sm font-medium text-gray-700">Para confirmar, escribí: <span class="font-mono text-gray-900">{{ $fraseEnvio }}</span></label>
                                <input type="text" name="frase" autocomplete="off" placeholder="{{ $fraseEnvio }}"
                                       class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono">
                                <div class="mt-5 flex items-center justify-end gap-3">
                                    <button type="button" onclick="document.getElementById('modal-enviar-contabilidad').classList.add('hidden')"
                                            class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50">Cancelar</button>
                                    <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Enviar ahora</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
