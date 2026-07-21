<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Compras — documentos recibidos</h2>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('documentos-recibidos.exportar', request()->query()) }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                    Exportar Excel
                </a>
                <form method="POST" action="{{ route('documentos-recibidos.sincronizar') }}">
                    @csrf
                    <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                            @disabled(! $fuenteDisponible)
                            title="Revisión rápida: lee solo desde la fecha del último documento guardado (solo lectura; no marca leído, no mueve ni borra).">
                        {{ $fuenteDisponible ? 'Revisar correos recientes' : 'Configurar correo Yahoo/IMAP' }}
                    </button>
                </form>
                @if ($fuenteDisponible)
                    <form method="POST" action="{{ route('documentos-recibidos.sincronizar') }}"
                          onsubmit="return confirm('Revisar el histórico completo puede tardar porque revisa correos antiguos. ¿Continuar?');">
                        @csrf
                        <input type="hidden" name="historico" value="1">
                        <button class="rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                                title="Revisa todo el buzón. Puede tardar porque revisa correos antiguos.">
                            Revisar histórico
                        </button>
                    </form>
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

            {{-- Aviso: herramienta interna para preparar lo que se le manda a la contadora --}}
            <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-800">
                Herramienta interna para preparar los documentos que le enviás a contabilidad (la contadora no entra al
                sistema). Estos son los CCF/facturas de proveedores que llegan al correo; los documentos que vos emitís
                están en <a href="{{ route('facturacion.reporte-contadora') }}" class="font-medium underline">Facturación → Reporte contadora</a>.
                El paquete mensual consolidado (emitidos + recibidos) llega en una fase posterior.
            </div>

            @unless ($fuenteDisponible)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                    El correo de documentos recibidos (Yahoo/IMAP) no está configurado ({{ $fuente }}). La revisión
                    está deshabilitada hasta configurar las variables <code>DOCUMENTOS_RECIBIDOS_MAIL_*</code> en el
                    servidor. El listado de abajo muestra lo ya registrado localmente.
                </div>
            @endunless

            {{-- Pestañas (estado) --}}
            @php
                $tabs = [
                    'pendientes' => ['Pendientes contabilidad', $conteos['pendiente']],
                    'bandeja' => ['Bandeja recibidos', null],
                    'enviados' => ['Enviados a contabilidad', $conteos['enviado']],
                    'ignorados' => ['Ignorados', $conteos['ignorado']],
                ];
            @endphp
            <nav class="flex flex-wrap gap-2 border-b border-gray-200">
                @foreach ($tabs as $clave => [$titulo, $conteo])
                    <a href="{{ route('documentos-recibidos.index', array_merge(request()->except(['vista', 'page']), ['vista' => $clave])) }}"
                       class="px-4 py-2 text-sm font-medium rounded-t-md {{ $filtros['vista'] === $clave ? 'bg-white border border-b-0 border-gray-200 text-indigo-600' : 'text-gray-500 hover:text-gray-700' }}">
                        {{ $titulo }}
                        @if ($conteo !== null)
                            <span class="ms-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $conteo }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            {{-- Filtros rápidos de rango --}}
            @php
                $rangos = ['mes_actual' => 'Este mes', 'mes_pasado' => 'Mes pasado', 'ultimos_7' => 'Últimos 7 días', 'todos' => 'Todos'];
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($rangos as $clave => $titulo)
                    <a href="{{ route('documentos-recibidos.index', array_merge(request()->except(['rango', 'fecha_desde', 'fecha_hasta', 'page']), ['rango' => $clave])) }}"
                       class="rounded-full px-3 py-1 text-xs font-medium {{ $filtros['rango'] === $clave ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $titulo }}
                    </a>
                @endforeach
                @if ($filtros['rango'] === 'personalizado')
                    <span class="rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white">Rango personalizado</span>
                @endif
            </div>

            {{-- Filtros detallados --}}
            <form method="GET" class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-4">
                <input type="hidden" name="vista" value="{{ $filtros['vista'] }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha desde</label>
                        <input type="date" name="fecha_desde" value="{{ $filtros['fecha_desde'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha hasta</label>
                        <input type="date" name="fecha_hasta" value="{{ $filtros['fecha_hasta'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Emisor</label>
                        <input type="text" name="emisor" value="{{ $filtros['emisor'] }}" placeholder="proveedor…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Tipo</label>
                        <select name="tipo_documento" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Todos</option>
                            <option value="03" @selected($filtros['tipo_documento'] === '03')>CCF</option>
                            <option value="01" @selected($filtros['tipo_documento'] === '01')>Factura</option>
                            <option value="05" @selected($filtros['tipo_documento'] === '05')>Nota de crédito</option>
                            <option value="11" @selected($filtros['tipo_documento'] === '11')>Factura de exportación</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Número de control</label>
                        <input type="text" name="numero_control" value="{{ $filtros['numero_control'] }}" placeholder="DTE-03-…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Código de generación</label>
                        <input type="text" name="codigo_generacion" value="{{ $filtros['codigo_generacion'] }}" placeholder="UUID…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Monto mínimo</label>
                        <input type="number" step="0.01" name="monto_min" value="{{ $filtros['monto_min'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Monto máximo</label>
                        <input type="number" step="0.01" name="monto_max" value="{{ $filtros['monto_max'] }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Aplicar filtros</button>
                    <a href="{{ route('documentos-recibidos.index', ['vista' => $filtros['vista']]) }}" class="text-sm text-gray-500 hover:underline">Limpiar</a>
                    <label class="ms-auto flex items-center gap-2 text-xs text-gray-500">
                        Por página
                        <select name="por_pagina" onchange="this.form.submit()" class="rounded-md border-gray-300 text-sm">
                            @foreach (\App\Services\DocumentosRecibidos\DocumentosRecibidosQuery::POR_PAGINA as $n)
                                <option value="{{ $n }}" @selected($filtros['por_pagina'] === $n)>{{ $n }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </form>

            {{-- Resumen del filtro actual --}}
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="rounded-lg bg-white ring-1 ring-gray-200 p-3">
                    <p class="text-xs text-gray-400">Documentos</p>
                    <p class="text-lg font-semibold text-gray-800">{{ number_format($resumen['total_docs']) }}</p>
                </div>
                <div class="rounded-lg bg-white ring-1 ring-gray-200 p-3">
                    <p class="text-xs text-gray-400">Total</p>
                    <p class="text-lg font-semibold text-gray-800">${{ number_format($resumen['total_monto'], 2) }}</p>
                </div>
                <div class="rounded-lg bg-amber-50 ring-1 ring-amber-200 p-3">
                    <p class="text-xs text-amber-600">Pendientes</p>
                    <p class="text-lg font-semibold text-amber-700">{{ number_format($resumen['pendiente']) }}</p>
                </div>
                <div class="rounded-lg bg-green-50 ring-1 ring-green-200 p-3">
                    <p class="text-xs text-green-600">Enviados</p>
                    <p class="text-lg font-semibold text-green-700">{{ number_format($resumen['enviado']) }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 ring-1 ring-gray-200 p-3">
                    <p class="text-xs text-gray-400">Ignorados</p>
                    <p class="text-lg font-semibold text-gray-600">{{ number_format($resumen['ignorado']) }}</p>
                </div>
            </div>

            {{-- Listado --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-3">Fecha correo</th>
                                <th class="py-3 px-3">Fecha DTE</th>
                                <th class="py-3 px-3">Emisor</th>
                                <th class="py-3 px-3">Tipo</th>
                                <th class="py-3 px-3">Número de control</th>
                                <th class="py-3 px-3 text-right">Total</th>
                                <th class="py-3 px-3 text-center">Adjuntos</th>
                                <th class="py-3 px-3 text-center">Clasificación</th>
                                <th class="py-3 px-3 text-center">Estado</th>
                                <th class="py-3 px-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $badge = ['pendiente' => 'bg-amber-100 text-amber-700', 'enviado' => 'bg-green-100 text-green-700', 'ignorado' => 'bg-gray-100 text-gray-500'];
                                // Motivo de datos faltantes (independiente de `estado`). Ver DocumentoRecibido::CLASIFICACIONES.
                                $badgeClasificacion = [
                                    'dte_valido' => 'bg-green-100 text-green-700',
                                    'no_es_dte' => 'bg-gray-100 text-gray-500',
                                    'json_invalido' => 'bg-red-100 text-red-700',
                                    'tipo_no_soportado' => 'bg-amber-100 text-amber-700',
                                    'falta_adjunto' => 'bg-amber-100 text-amber-700',
                                ];
                                // Cuando no hay total, el motivo (no la palabra "—") explica por qué.
                                $sinTotalPorClasificacion = [
                                    'no_es_dte' => 'No es DTE',
                                    'json_invalido' => 'JSON inválido',
                                    'tipo_no_soportado' => 'Tipo no soportado',
                                    'falta_adjunto' => 'Falta JSON',
                                ];
                            @endphp
                            @forelse ($documentos as $doc)
                                <tr class="hover:bg-gray-50 {{ $doc->estado === 'ignorado' ? 'opacity-60' : '' }}">
                                    <td class="py-2 px-3 text-gray-600">{{ optional($doc->fecha_correo)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600">{{ optional($doc->fecha_dte)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2 px-3">
                                        <div class="font-medium text-gray-800">{{ $doc->emisor_nombre ?? $doc->remitente ?? '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ $doc->emisor_nit ? 'NIT '.$doc->emisor_nit : '' }}{{ $doc->emisor_nrc ? ' · NRC '.$doc->emisor_nrc : '' }}</div>
                                    </td>
                                    <td class="py-2 px-3 text-gray-600">{{ $doc->tipoLabel() }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-600">{{ $doc->numero_control ?? '—' }}</td>
                                    <td class="py-2 px-3 text-right text-gray-700">
                                        @if ($doc->total !== null)
                                            <span title="{{ $doc->totalLabel() }}">${{ number_format((float) $doc->total, 2) }}</span>
                                            @if ($doc->tipo_documento === '07')
                                                <div class="text-xs text-gray-400">{{ $doc->totalLabel() }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400">{{ $sinTotalPorClasificacion[$doc->clasificacion] ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-xs {{ $doc->tiene_pdf ? 'bg-rose-100 text-rose-700' : 'bg-gray-100 text-gray-400' }}">PDF</span>
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-xs {{ $doc->tiene_json ? 'bg-sky-100 text-sky-700' : 'bg-gray-100 text-gray-400' }}">JSON</span>
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClasificacion[$doc->clasificacion] ?? 'bg-gray-100 text-gray-400' }}">{{ $doc->clasificacionLabel() }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$doc->estado] ?? 'bg-gray-100 text-gray-600' }}">{{ ucfirst($doc->estado) }}</span>
                                    </td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($doc->estado !== 'pendiente')
                                                <form method="POST" action="{{ route('documentos-recibidos.pendiente', $doc) }}">
                                                    @csrf @method('PATCH')
                                                    <button class="text-indigo-600 hover:underline text-xs">Pendiente</button>
                                                </form>
                                            @endif
                                            @if ($doc->estado !== 'enviado')
                                                <form method="POST" action="{{ route('documentos-recibidos.enviado', $doc) }}"
                                                      title="Marca que ya se lo hiciste llegar a contabilidad por fuera. No envía correo.">
                                                    @csrf @method('PATCH')
                                                    <button class="text-green-700 hover:underline text-xs">Marcar enviado</button>
                                                </form>
                                            @endif
                                            @if ($doc->estado !== 'ignorado')
                                                <form method="POST" action="{{ route('documentos-recibidos.ignorar', $doc) }}">
                                                    @csrf @method('PATCH')
                                                    <button class="text-gray-500 hover:underline text-xs">Ignorar</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="py-10 text-center text-gray-400">
                                    No hay documentos para este filtro.
                                    @if ($fuenteDisponible) Usá "Revisar correos" para buscar nuevos, o cambiá el rango a "Todos". @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($documentos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $documentos->links() }}</div>
                @endif
            </div>

            {{-- Preparado para fase futura (no activo todavía) --}}
            <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-xs text-gray-500">
                <span class="font-medium text-gray-600">Próxima fase (pendiente):</span>
                paquete mensual para la contadora (ZIP con Excel de emitidos + recibidos y carpetas PDF/JSON), y envío a
                contabilidad con confirmación explícita.
                <span class="ms-2 inline-flex gap-2">
                    <button type="button" disabled class="rounded bg-gray-200 px-2 py-1 text-gray-400 cursor-not-allowed">Descargar ZIP (pendiente)</button>
                    <button type="button" disabled class="rounded bg-gray-200 px-2 py-1 text-gray-400 cursor-not-allowed">Enviar a contabilidad (pendiente)</button>
                </span>
            </div>

            <p class="text-xs text-gray-400">
                Solo lectura y preparación. La revisión del buzón (Yahoo/IMAP) es de solo lectura (no marca leído, no
                mueve ni borra correos) y no se envía ningún correo desde esta pantalla.
            </p>
        </div>
    </div>
</x-app-layout>
