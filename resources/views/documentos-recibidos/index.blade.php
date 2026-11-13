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
                {{-- Sincronización del buzón Yahoo/IMAP: SOLO administrador (documentos-recibidos.gestionar).
                     El botón dejó de ser la única defensa: la sincronización la hace el scheduler y
                     esto solo la adelanta. El estado permanente está en la franja de abajo. --}}
                @can('documentos-recibidos.gestionar')
                    <form method="POST" action="{{ route('documentos-recibidos.sincronizar') }}">
                        @csrf
                        <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                @disabled(! $fuenteDisponible)
                                title="Adelanta la revisión incremental que ya corre sola (solo lectura; no marca leído, no mueve ni borra).">
                            {{ $fuenteDisponible ? 'Revisar ahora' : 'Configurar correo Yahoo/IMAP' }}
                        </button>
                    </form>
                @endcan
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

            {{-- Candado de correo real: solo aparece fuera de producción. --}}
            <x-correo-simulado-aviso />

            @if ($fuenteDisponible)
                @php
                    /* Tres roles de texto, con variante `dark:` explícita en cada uno.
                       Los overrides globales de app.css solo cubren los pasos 600-800, así
                       que en oscuro un `text-red-900` quedaba rojo oscuro sobre fondo rojo
                       oscuro: ilegible. La jerarquía se conserva invertida —en claro el
                       título es el paso MÁS oscuro, en oscuro el MÁS claro— y los tres
                       tonos pasan AA sobre el fondo translúcido del aviso. */
                    $tono = match ($estadoSync['color']) {
                        'verde' => [
                            'caja' => 'bg-green-50 border-green-200',
                            'punto' => 'bg-green-500',
                            'texto' => 'text-green-900 dark:text-green-200',
                            'detalle' => 'text-green-800 dark:text-green-300',
                            'meta' => 'text-green-700 dark:text-green-400',
                        ],
                        'rojo' => [
                            'caja' => 'bg-red-50 border-red-200',
                            'punto' => 'bg-red-500',
                            'texto' => 'text-red-900 dark:text-red-200',
                            'detalle' => 'text-red-800 dark:text-red-300',
                            'meta' => 'text-red-700 dark:text-red-400',
                        ],
                        default => [
                            'caja' => 'bg-amber-50 border-amber-200',
                            'punto' => 'bg-amber-500',
                            'texto' => 'text-amber-900 dark:text-amber-200',
                            'detalle' => 'text-amber-800 dark:text-amber-300',
                            'meta' => 'text-amber-700 dark:text-amber-400',
                        ],
                    };
                @endphp

                {{-- ESTADO PERMANENTE de la sincronización. Sustituye al mensaje que se
                     perdía al recargar la página: ya no hace falta acordarse de apretar un
                     botón, hace falta poder mirar. --}}
                <div class="rounded-lg border {{ $tono['caja'] }} p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="mt-1.5 h-2.5 w-2.5 flex-none rounded-full {{ $tono['punto'] }}"></span>
                            <div>
                                <p class="text-sm font-semibold {{ $tono['texto'] }}">{{ $estadoSync['titulo'] }}</p>
                                <p class="text-sm {{ $tono['detalle'] }}">{{ $estadoSync['detalle'] }}</p>
                                <p class="mt-1 text-xs {{ $tono['meta'] }}">
                                    Última sincronización correcta:
                                    <span class="font-medium">{{ $estadoSync['ultimo_exito'] ?? 'nunca' }}</span>
                                    · Revisión automática cada 15 minutos.
                                </p>
                            </div>
                        </div>
                        @can('documentos-recibidos.gestionar')
                            <button type="button"
                                    onclick="document.getElementById('recuperar-periodo').classList.toggle('hidden')"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                Recuperar período
                            </button>
                        @endcan
                    </div>

                    @can('documentos-recibidos.gestionar')
                        {{-- Herramienta EXCEPCIONAL: pide fechas y encola el recorrido día por
                             día. No depende de subir ningún límite. --}}
                        <form id="recuperar-periodo" method="POST"
                              class="{{ $errors->any() ? '' : 'hidden' }} mt-4 rounded-md border border-gray-200 bg-white p-4"
                              action="{{ route('documentos-recibidos.recuperar') }}">
                            @csrf
                            <p class="text-sm font-medium text-gray-800">Recuperar un período del buzón</p>
                            <p class="mt-1 text-xs text-gray-500">
                                Lee el buzón día por día, de más viejo a más nuevo, y guarda el avance. Se puede
                                interrumpir y continuar: no duplica nada. Solo lectura: no marca leído, no mueve, no borra.
                            </p>
                            <div class="mt-3 flex flex-wrap items-end gap-3">
                                <div>
                                    <label for="rec-desde" class="block text-xs font-medium text-gray-600">Desde</label>
                                    <input id="rec-desde" type="date" name="desde" required
                                           value="{{ old('desde', $estadoSync['primer_dia_pendiente']) }}"
                                           class="mt-1 rounded-md border-gray-300 text-sm">
                                </div>
                                <div>
                                    <label for="rec-hasta" class="block text-xs font-medium text-gray-600">Hasta</label>
                                    <input id="rec-hasta" type="date" name="hasta" required
                                           value="{{ old('hasta', now()->toDateString()) }}"
                                           class="mt-1 rounded-md border-gray-300 text-sm">
                                </div>
                                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">
                                    Recuperar
                                </button>
                            </div>
                            @error('desde') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                            @error('hasta') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                        </form>
                    @endcan
                </div>
            @endif

            @if ($sinFechaFiscal > 0)
                {{-- Sin fecha de emisión legible no se puede saber a qué mes pertenece la
                     compra, así que NO entra en ningún paquete. Se avisa acá en vez de
                     dejarla desaparecer del período en silencio. --}}
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 dark:text-amber-300">
                    <span class="font-semibold">{{ $sinFechaFiscal }}</span> compra(s) sin fecha de emisión legible: no entran en
                    ningún paquete mensual hasta que se resuelvan. Suelen ser PDF sin JSON, o un JSON que no se pudo leer.
                    <a class="font-medium underline" href="{{ route('documentos-recibidos.index', ['vista' => 'bandeja', 'rango' => 'todos']) }}">Verlas en la bandeja</a>.
                </div>
            @endif

            @unless ($fuenteDisponible)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800 dark:text-amber-300">
                    El correo de documentos recibidos (Yahoo/IMAP) no está configurado ({{ $fuente }}). La revisión
                    está deshabilitada hasta configurar las variables <code>DOCUMENTOS_RECIBIDOS_MAIL_*</code> en el
                    servidor. El listado de abajo muestra lo ya registrado localmente.
                </div>
            @endunless

            {{-- Pestañas (estado). «Bandeja» va primero porque es la de por defecto:
                 muestra todas las compras del rango, también las ya enviadas. --}}
            @php
                $tabs = [
                    'bandeja' => ['Bandeja recibidos', null],
                    'pendientes' => ['Pendientes contabilidad', $conteos['pendiente']],
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
                // Jefatura (13/11/2026): sin número de control completo, código de generación ni
                // JSON (DocumentoRecibidoController le da 403 al JSON); el número corto y el PDF.
                $soloNegocio = (bool) auth()->user()?->abreDocumentosComoPdf();
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($rangos as $clave => $titulo)
                    <a href="{{ route('documentos-recibidos.index', array_merge(request()->except(['rango', 'fecha_desde', 'fecha_hasta', 'page']), ['rango' => $clave])) }}"
                       class="inline-flex min-h-11 sm:min-h-0 items-center rounded-full px-3 py-1 text-xs font-medium {{ $filtros['rango'] === $clave ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                        {{ $titulo }}
                    </a>
                @endforeach
                @if ($filtros['rango'] === 'personalizado')
                    <span class="rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white">Rango personalizado</span>
                @endif
            </div>

            {{-- Filtros detallados. En el celular se pliegan detrás de «Filtros»; en escritorio,
                 como siempre. Cuenta solo los filtros del formulario (el rango tiene sus pastillas). --}}
            @php $filtrosPuestos = collect(['fecha_desde', 'fecha_hasta', 'emisor', 'tipo_documento', 'numero_control', 'codigo_generacion', 'monto_min', 'monto_max'])->filter(fn ($k) => filled($filtros[$k] ?? null))->count(); @endphp
            <x-filtros-celular :activos="$filtrosPuestos" id="filtros-compras" class="px-4 sm:px-0">
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
                    @unless ($soloNegocio)
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Número de control</label>
                        <input type="text" name="numero_control" value="{{ $filtros['numero_control'] }}" placeholder="DTE-03-…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Código de generación</label>
                        <input type="text" name="codigo_generacion" value="{{ $filtros['codigo_generacion'] }}" placeholder="UUID…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    @endunless
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
                    <button class="min-h-11 sm:min-h-0 rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Aplicar filtros</button>
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
            </x-filtros-celular>

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
                {{-- Celular (debajo de md): cada compra se apila como tarjeta (emisor arriba; fechas,
                     total y estado debajo; las acciones al pie). Desde md, la tabla de siempre. --}}
                <div class="overflow-x-auto">
                    <table class="block md:table min-w-full text-sm">
                        <thead class="hidden md:table-header-group">
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-3">Fecha correo</th>
                                <th class="py-3 px-3">Fecha DTE</th>
                                <th class="py-3 px-3">Emisor</th>
                                <th class="py-3 px-3">Tipo</th>
                                <th class="py-3 px-3">{{ $soloNegocio ? 'Número' : 'Número de control' }}</th>
                                <th class="py-3 px-3 text-right">Total</th>
                                <th class="py-3 px-3 text-center">Estado</th>
                                <th class="py-3 px-3 text-right">Contabilidad y acciones</th>
                            </tr>
                        </thead>
                        <tbody class="block md:table-row-group divide-y divide-gray-100">
                            @php
                                // «Pendiente» en gris, no en ámbar: es lo normal hasta que sale el paquete
                                // mensual, no algo que haya que atender compra por compra.
                                $badge = ['pendiente' => 'bg-gray-100 text-gray-600', 'enviado' => 'bg-green-100 text-green-700', 'ignorado' => 'bg-gray-100 text-gray-500'];
                                // Cuando no hay total, el motivo (no la palabra "—") explica por qué.
                                $sinTotalPorClasificacion = [
                                    'no_es_dte' => 'No es DTE',
                                    'json_invalido' => 'JSON inválido',
                                    'tipo_no_soportado' => 'Tipo no soportado',
                                    'falta_adjunto' => 'Falta JSON',
                                ];
                                // Estado del envío individual a contabilidad. SOLO 'enviado' cuenta
                                // como enviado: simulado / error / en cola siguen pendientes. Sin
                                // envío individual no se muestra nada: las compras viajan en el
                                // paquete mensual y el aviso es uno solo, el de mandar el paquete.
                                $envioBadge = [
                                    'pendiente' => ['En cola', 'bg-amber-100 text-amber-800', 'El envío está en la cola: todavía no salió.'],
                                    'enviado' => ['Enviado', 'bg-green-100 text-green-700', 'El correo salió correctamente.'],
                                    'simulado' => ['Simulado', 'bg-amber-100 text-amber-800', 'El correo NO salió por SMTP (mailer en modo prueba): sigue pendiente.'],
                                    'error' => ['Error', 'bg-red-100 text-red-700', 'El envío falló: sigue pendiente.'],
                                ];
                                $puedeEnviarContabilidad = auth()->user()?->can('contabilidad.enviar');
                            @endphp
                            @forelse ($documentos as $doc)
                                <tr class="grid grid-cols-2 py-2 md:table-row hover:bg-gray-50 {{ $doc->estado === 'ignorado' ? 'opacity-60' : '' }}">
                                    <td class="py-2 px-3 text-gray-600"><span class="md:hidden block text-xs font-sans font-normal text-gray-500">Fecha correo</span>{{ optional($doc->fecha_correo)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2 px-3 text-gray-600"><span class="md:hidden block text-xs font-sans font-normal text-gray-500">Fecha DTE</span>{{ optional($doc->fecha_dte)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="col-span-2 py-2 px-3">
                                        <div class="font-medium text-gray-800">{{ $doc->emisor_nombre ?? $doc->remitente ?? '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ $doc->emisor_nit ? 'NIT '.$doc->emisor_nit : '' }}{{ $doc->emisor_nrc ? ' · NRC '.$doc->emisor_nrc : '' }}</div>
                                    </td>
                                    <td class="py-2 px-3 text-gray-600">
                                        {{ $doc->tipoLabel() }}
                                        {{-- Ya no hay columnas de adjuntos ni de «DTE válido»: solo se marca
                                             el que NO es un DTE válido (mismo criterio que la columna), para
                                             que no se le pase a nadie antes de mandárselo a la contadora. --}}
                                        @if ($doc->clasificacion !== 'dte_valido')
                                            <div class="mt-1">
                                                <span class="inline-flex whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700"
                                                      title="No es un DTE válido: revisalo antes de mandarlo a contabilidad.">{{ $doc->clasificacionLabel() }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-600 break-all md:break-normal"><span class="md:hidden block text-xs font-sans font-normal text-gray-500">{{ $soloNegocio ? 'Número' : 'Número de control' }}</span>{{ $soloNegocio ? ($doc->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($doc->numero_control) : '—') : ($doc->numero_control ?? '—') }}</td>
                                    <td class="py-2 px-3 md:text-right text-gray-700">
                                        <span class="md:hidden block text-xs font-sans font-normal text-gray-500">Total</span>
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
                                        <span class="md:hidden text-xs text-gray-500">Estado</span>
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$doc->estado] ?? 'bg-gray-100 text-gray-600' }}">{{ ucfirst($doc->estado) }}</span>
                                    </td>
                                    {{-- Contabilidad y acciones: envío real (contabilidad.enviar), badge del
                                         estado del envío y menú discreto con las descargas y el triage
                                         interno (documentos-recibidos.gestionar). --}}
                                    @php
                                        $envio = $envioBadge[(string) ($doc->envio_estado ?? '')] ?? null;
                                        $envioExitoso = $doc->envio_estado === 'enviado';
                                    @endphp
                                    <td class="col-span-2 py-2 px-3 align-top">
                                        <div class="flex items-start justify-end gap-3">
                                            {{-- Acción principal: la factura armada desde su JSON. --}}
                                            <a href="{{ route('documentos-recibidos.ver', $doc) }}"
                                               class="inline-flex min-h-11 sm:min-h-0 items-center py-1.5 text-sm font-medium text-indigo-600 hover:underline">Ver</a>
                                            <div class="flex flex-col items-end gap-1">
                                                @if ($puedeEnviarContabilidad)
                                                    @php($accion = $envioExitoso ? 'Reenviar a contabilidad' : 'Enviar a contabilidad')
                                                    <form method="POST" action="{{ route('documentos-recibidos.enviar-contabilidad', $doc) }}"
                                                          onsubmit="return confirm('¿Enviar este documento recibido a contabilidad por correo?');">
                                                        @csrf
                                                        <button type="submit" aria-label="{{ $accion }}"
                                                                title="{{ $accion }}: adjunta los archivos originales ya guardados (PDF/JSON)."
                                                                class="min-h-11 sm:min-h-0 rounded-md bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-700">
                                                            {{ $envioExitoso ? 'Reenviar' : 'Enviar' }}
                                                        </button>
                                                    </form>
                                                @endif

                                                @if ($envio)
                                                    <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $envio[1] }}"
                                                          title="{{ $doc->envio_estado === 'error' && $doc->envio_error ? $doc->envio_error : $envio[2] }}">{{ $envio[0] }}</span>
                                                @endif

                                                @if ($envioExitoso && $doc->envio_enviado_at)
                                                    <span class="text-[11px] whitespace-nowrap text-gray-400">{{ \Illuminate\Support\Carbon::parse($doc->envio_enviado_at)->format('d/m/Y H:i') }}</span>
                                                @endif
                                            </div>

                                            <details class="text-left">
                                                <summary title="Más acciones" aria-label="Más acciones"
                                                         class="inline-flex min-h-11 sm:min-h-0 items-center cursor-pointer list-none rounded-md px-2 py-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 [&::-webkit-details-marker]:hidden">⋮</summary>
                                                <div class="mt-1 flex flex-col gap-1 whitespace-nowrap rounded-md bg-gray-50 p-2 ring-1 ring-gray-200">
                                                    @if ($doc->tiene_pdf)
                                                        <a href="{{ route('documentos-recibidos.archivo', [$doc, 'pdf']) }}" target="_blank" rel="noopener"
                                                           class="text-xs font-medium text-gray-600 underline hover:text-gray-900">Abrir PDF</a>
                                                    @else
                                                        <span class="cursor-not-allowed text-xs text-gray-300" title="Este documento no tiene PDF guardado.">Abrir PDF</span>
                                                    @endif

                                                    @if ($soloNegocio)
                                                        {{-- Sin JSON para Jefatura. --}}
                                                    @elseif ($doc->tiene_json)
                                                        <a href="{{ route('documentos-recibidos.archivo', [$doc, 'json']) }}"
                                                           class="text-xs font-medium text-gray-600 underline hover:text-gray-900">Abrir JSON</a>
                                                    @else
                                                        <span class="cursor-not-allowed text-xs text-gray-300" title="Este documento no tiene JSON guardado.">Abrir JSON</span>
                                                    @endif

                                                    @can('documentos-recibidos.gestionar')
                                                        @if ($doc->estado !== 'ignorado')
                                                            <form method="POST" action="{{ route('documentos-recibidos.ignorar', $doc) }}">
                                                                @csrf @method('PATCH')
                                                                <button class="text-xs text-gray-500 hover:underline">Ignorar</button>
                                                            </form>
                                                        @endif
                                                        @if ($doc->estado !== 'pendiente')
                                                            <form method="POST" action="{{ route('documentos-recibidos.pendiente', $doc) }}">
                                                                @csrf @method('PATCH')
                                                                <button class="text-xs text-indigo-600 hover:underline">Marcar pendiente</button>
                                                            </form>
                                                        @endif
                                                    @endcan
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr class="block md:table-row"><td colspan="8" class="block md:table-cell py-10 text-center text-gray-400">
                                    No hay documentos para este filtro.
                                    @if ($fuenteDisponible) Usá "Revisar ahora" para adelantar la revisión, o cambiá el rango a "Todos". @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($documentos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $documentos->links() }}</div>
                @endif
            </div>

            <p class="text-xs text-gray-400">
                Solo lectura y preparación. La revisión del buzón (Yahoo/IMAP) es de solo lectura (no marca leído, no
                mueve ni borra correos) y no se envía ningún correo desde esta pantalla.
            </p>
        </div>
    </div>
</x-app-layout>
