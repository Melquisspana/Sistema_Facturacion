<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ventas</h2>
    </x-slot>

    @php
        // Chips de filtro rápido: conservan los demás parámetros de la URL.
        $chip = fn (array $extra) => route('facturacion.reporte-contadora', array_merge(request()->query(), $extra));
        $chipClases = fn (bool $activo) => $activo
            ? 'bg-gray-800 text-white ring-gray-800'
            : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50';
        // Badge del envío a contabilidad, DENTRO de la columna Acciones (no hay columna
        // aparte). SOLO 'enviado' cuenta como enviado: 'simulado' (mailer no real), 'error'
        // y 'pendiente' (en cola) siguen pendientes; sin envío por el canal = Pendiente.
        $contaBadge = [
            '' => ['Pendiente', 'bg-gray-100 text-gray-600', 'Todavía no se envió a contabilidad.'],
            'pendiente' => ['En cola', 'bg-amber-100 text-amber-800', 'El envío está en la cola: todavía no salió.'],
            'enviado' => ['Enviado', 'bg-green-100 text-green-700', 'El correo salió correctamente.'],
            'simulado' => ['Simulado', 'bg-amber-100 text-amber-800', 'El correo NO salió por SMTP (mailer en modo prueba): sigue pendiente.'],
            'error' => ['Error', 'bg-red-100 text-red-700', 'El envío falló: sigue pendiente.'],
        ];
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">{{ session('error') }}</div>
            @endif

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
                </div>
            </form>

            {{-- Filtros rápidos (no dependen del formulario: conservan el resto de la URL) --}}
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs uppercase tracking-wide text-gray-400">Rápidos</span>
                @foreach (['este_mes' => 'Este mes', 'mes_pasado' => 'Mes pasado', 'ultimos_7' => 'Últimos 7 días'] as $clave => $etiqueta)
                    <a href="{{ $chip(['rango' => $clave]) }}"
                       class="rounded-full px-3 py-1 text-xs font-medium ring-1 {{ $chipClases($filtros['rango'] === $clave) }}">{{ $etiqueta }}</a>
                @endforeach
                <span class="mx-1 h-4 w-px bg-gray-200"></span>
                @foreach (['pendientes' => 'Pendientes de enviar a contabilidad', 'enviados' => 'Enviados a contabilidad'] as $clave => $etiqueta)
                    <a href="{{ $chip(['contabilidad' => $clave]) }}"
                       class="rounded-full px-3 py-1 text-xs font-medium ring-1 {{ $chipClases($filtros['contabilidad'] === $clave) }}">{{ $etiqueta }}</a>
                @endforeach
                @if ($filtros['rango'] !== 'personalizado' || $filtros['contabilidad'] !== 'todos')
                    <a href="{{ route('facturacion.reporte-contadora') }}" class="text-xs text-gray-500 underline hover:text-gray-700">Limpiar</a>
                @endif
            </div>

            {{-- Vista previa --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                    <span>Vista previa (hasta 500 filas). El Excel exporta todo el rango filtrado.</span>
                    @if ($correoContabilidad)
                        <span>Contabilidad: <span class="font-medium text-gray-700">{{ $correoContabilidad }}</span></span>
                    @else
                        <span class="text-amber-700">Sin correo de contabilidad configurado (Configuración &gt; Contabilidad).</span>
                    @endif
                </div>
                {{-- Tabla ancha a propósito: se prefiere un scroll horizontal moderado antes que
                     comprimir las columnas hasta hacerlas ilegibles. El estado del envío a
                     contabilidad vive DENTRO de la columna Acciones. --}}
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Fecha</th>
                                <th class="py-3 px-4">Tipo</th>
                                <th class="py-3 px-4">Cliente</th>
                                <th class="py-3 px-4">NIT</th>
                                <th class="py-3 px-4">Número de control</th>
                                <th class="py-3 px-4">Sello</th>
                                <th class="py-3 px-4">Estado</th>
                                <th class="py-3 px-4 text-right">Gravado</th>
                                <th class="py-3 px-4 text-right">IVA</th>
                                <th class="py-3 px-4 text-right">Retención</th>
                                <th class="py-3 px-4 text-right">Total</th>
                                <th class="py-3 px-4 text-center">Correo</th>
                                <th class="py-3 px-4 text-right">Contabilidad y acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dtes as $dte)
                                @php
                                    $enviado = in_array($dte->ultimo_envio_estado ?? null, ['enviado', 'simulado'], true);
                                    $conta = $contaBadge[(string) ($dte->envio_conta_estado ?? '')] ?? $contaBadge[''];
                                    $aceptadoReal = $dte->aceptadoRealmentePorMh();
                                    $tieneJson = filled($dte->json_generado_path);
                                    // "Reenviar" solo cuando ya hubo un envío EXITOSO a contabilidad.
                                    $exitoso = $dte->envio_conta_estado === 'enviado';
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 whitespace-nowrap text-gray-600">{{ optional($dte->fecha_emision)->format('d/m/Y') }}</td>
                                    <td class="py-3 px-4 whitespace-nowrap text-gray-600">{{ $dte->tipo_dte?->label() }}</td>
                                    <td class="py-3 px-4 text-gray-800">{{ $dte->cliente?->nombre ?? '—' }}</td>
                                    <td class="py-3 px-4 whitespace-nowrap text-gray-600">{{ $dte->cliente?->num_documento ?? '—' }}</td>
                                    {{-- Datos largos: se truncan por CSS, el valor completo va en title. --}}
                                    <td class="py-3 px-4 font-mono text-xs text-gray-600">
                                        <div class="max-w-[11rem] truncate" title="{{ $dte->numero_control ?? '' }}">{{ $dte->numero_control ?? '—' }}</div>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-xs text-gray-500">
                                        <div class="max-w-[8rem] truncate" title="{{ $dte->sello_recepcion ?? '' }}">{{ $dte->sello_recepcion ?: '—' }}</div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap text-gray-600">{{ $dte->estado?->label() }}</td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $dte->total_gravado, 2) }}</td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $dte->iva, 2) }}</td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $dte->iva_retenido, 2) }}</td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap font-medium text-gray-800">{{ number_format((float) $dte->total_pagar, 2) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $enviado ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $enviado ? 'Sí' : 'No' }}</span>
                                    </td>
                                    {{-- Contabilidad y acciones en UNA sola celda: acción principal
                                         (Enviar/Reenviar), badge del estado del envío y un menú
                                         discreto con las descargas (PDF / JSON). --}}
                                    <td class="py-3 px-4 align-top">
                                        <div class="flex items-start justify-end gap-3">
                                            <div class="flex flex-col items-end gap-1">
                                                @if ($puedeEnviar && $aceptadoReal)
                                                    @php($accion = $exitoso ? 'Reenviar a contabilidad' : 'Enviar a contabilidad')
                                                    <form method="POST" action="{{ route('facturacion.reporte-contadora.enviar', $dte) }}"
                                                          onsubmit="return confirm('¿Enviar este documento a contabilidad por correo?');">
                                                        @csrf
                                                        <button type="submit" @disabled(! $correoContabilidad)
                                                                aria-label="{{ $accion }}"
                                                                title="{{ $correoContabilidad ? $accion.': envía el PDF y el JSON oficial a '.$correoContabilidad : 'No hay un correo de contabilidad configurado.' }}"
                                                                class="rounded-md px-3 py-1.5 text-xs font-semibold text-white {{ $correoContabilidad ? 'bg-sky-600 hover:bg-sky-700' : 'bg-gray-300 cursor-not-allowed' }}">
                                                            {{ $exitoso ? 'Reenviar' : 'Enviar' }}
                                                        </button>
                                                    </form>
                                                @endif

                                                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $conta[1] }}"
                                                      title="{{ $dte->envio_conta_estado === 'error' && $dte->envio_conta_error ? $dte->envio_conta_error : $conta[2] }}">{{ $conta[0] }}</span>

                                                @if ($exitoso && $dte->envio_conta_enviado_at)
                                                    <span class="text-[11px] whitespace-nowrap text-gray-400">{{ \Illuminate\Support\Carbon::parse($dte->envio_conta_enviado_at)->format('d/m/Y H:i') }}</span>
                                                @endif
                                            </div>

                                            {{-- Menú "⋮": se despliega en la misma celda (no se recorta
                                                 con el scroll horizontal) y no necesita JavaScript. --}}
                                            <details class="text-left">
                                                <summary title="Más acciones" aria-label="Más acciones"
                                                         class="cursor-pointer list-none rounded-md px-2 py-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 [&::-webkit-details-marker]:hidden">⋮</summary>
                                                <div class="mt-1 flex flex-col gap-1 whitespace-nowrap rounded-md bg-gray-50 p-2 ring-1 ring-gray-200">
                                                    <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank" rel="noopener"
                                                       title="Ver la representación gráfica en PDF"
                                                       class="text-xs font-medium text-gray-600 underline hover:text-gray-900">Ver PDF</a>
                                                    @if ($tieneJson)
                                                        <a href="{{ route('facturacion.reporte-contadora.json', $dte) }}"
                                                           title="Descargar el JSON oficial del documento"
                                                           class="text-xs font-medium text-gray-600 underline hover:text-gray-900">Descargar JSON</a>
                                                    @else
                                                        <span class="cursor-not-allowed text-xs text-gray-300"
                                                              title="Este documento no tiene JSON oficial generado.">Descargar JSON</span>
                                                    @endif
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="py-10 text-center text-gray-400">No hay documentos para los filtros elegidos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
