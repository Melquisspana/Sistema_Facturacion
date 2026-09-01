<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Paquete mensual</h2>
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

            {{-- COBERTURA del período. Va ANTES del resumen a propósito: los totales de
                 abajo solo significan algo si se sabe que los correos del período están
                 leídos. Sin esto, un período al que le faltaban quince días se veía
                 exactamente igual que uno cerrado. --}}
            @php
                $cobOk = $cobertura['cubierto'] && ! $cobertura['ultimo_error'];
                /* Variante `dark:` explícita en cada tono: los overrides globales de
                   app.css solo cubren los pasos 600-800, así que un `text-*-900` quedaba
                   oscuro sobre el fondo oscuro del aviso. */
                $cobTono = $cobOk
                    ? [
                        'caja' => 'bg-green-50 ring-green-200',
                        'punto' => 'bg-green-500',
                        'titulo' => 'text-green-900 dark:text-green-200',
                        'texto' => 'text-green-800 dark:text-green-300',
                    ]
                    : [
                        'caja' => 'bg-amber-50 ring-amber-200',
                        'punto' => 'bg-amber-500',
                        'titulo' => 'text-amber-900 dark:text-amber-200',
                        'texto' => 'text-amber-800 dark:text-amber-300',
                    ];
                $diasPendientes = collect($cobertura['dias_pendientes']);
            @endphp
            <div class="rounded-xl ring-1 {{ $cobTono['caja'] }} p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="mt-1.5 h-2.5 w-2.5 flex-none rounded-full {{ $cobTono['punto'] }}"></span>
                        <div>
                            <h3 class="text-sm font-semibold {{ $cobTono['titulo'] }}">
                                {{ $cobOk ? 'Período completo: se revisaron todos los días' : 'Período incompleto' }}
                            </h3>
                            <p class="text-sm {{ $cobTono['texto'] }}">
                                {{ $cobertura['motivo'] ?? 'Todos los correos del período están leídos.' }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('documentos-recibidos.index') }}"
                       class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Ir a Compras
                    </a>
                </div>

                <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide {{ $cobTono['texto'] }}">Días revisados</dt>
                        <dd class="font-semibold {{ $cobTono['titulo'] }}">
                            {{ $cobertura['dias_completos'] }} / {{ $cobertura['dias_totales'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide {{ $cobTono['texto'] }}">Última sincronización</dt>
                        {{-- La corrida global si la hay; si no, el último día de ESTE período que
                             se cerró. Lo segundo es más específico y es lo que importa acá. --}}
                        <dd class="font-semibold {{ $cobTono['titulo'] }}">
                            {{ $cobertura['ultimo_exito'] ?? $cobertura['ultima_sincronizacion'] ?? 'nunca' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide {{ $cobTono['texto'] }}">Días con error</dt>
                        <dd class="font-semibold {{ $cobertura['dias_con_error'] > 0 ? 'text-red-700 dark:text-red-300' : $cobTono['titulo'] }}">
                            {{ $cobertura['dias_con_error'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide {{ $cobTono['texto'] }}">Correos procesados</dt>
                        <dd class="font-semibold {{ $cobTono['titulo'] }}">{{ number_format($cobertura['correos']) }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs {{ $cobTono['texto'] }}">
                    Compras válidas del período: <span class="font-semibold">{{ number_format($cobertura['compras_validas']) }}</span>
                    · repetidas y descartadas al leer: <span class="font-semibold">{{ number_format($cobertura['duplicados']) }}</span> /
                    <span class="font-semibold">{{ number_format($cobertura['descartados']) }}</span>
                    · rechazadas (sin DTE legible): <span class="font-semibold">{{ number_format($cobertura['rechazados']) }}</span>
                    · ignoradas manualmente, fuera del paquete: <span class="font-semibold">{{ number_format($cobertura['compras_ignoradas']) }}</span>
                </p>

                @if ($cobertura['compras_sin_fecha_fiscal'] > 0)
                    <p class="mt-2 rounded-md bg-white/70 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <span class="font-semibold">{{ $cobertura['compras_sin_fecha_fiscal'] }}</span> compra(s) sin fecha de emisión legible
                        NO entran en ningún período. Hay que resolverlas en Compras: no se cuelan por la fecha del correo.
                    </p>
                @endif

                @if ($diasPendientes->isNotEmpty())
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-medium {{ $cobTono['titulo'] }}">
                            Ver los {{ $diasPendientes->count() }} día(s) sin revisar
                        </summary>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($diasPendientes as $d)
                                <span class="rounded bg-white px-2 py-1 text-xs text-gray-700 ring-1 ring-gray-200 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-600"
                                      title="{{ $d['error'] ?? 'Sin revisar' }}">
                                    {{ $d['dia'] }}
                                    {{-- `text-gray-400` sobre el chip oscuro daba 3.3:1, por debajo de AA. --}}
                                    <span class="text-gray-500 dark:text-paper-300">· {{ $d['estado'] === 'sin_revisar' ? 'sin revisar' : $d['estado'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>

            {{-- Resumen --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Resumen del periodo</h3>
                    <span class="text-xs text-gray-500">Rango: {{ $rango['desde'] }} a {{ $rango['hasta'] }} ({{ $rango['etiqueta'] }})</span>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-sky-50 ring-1 ring-sky-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-sky-600">Compras (recibidos)</p>
                        <p class="mt-1 text-2xl font-semibold text-sky-800">{{ number_format($resumen['compras_cantidad']) }} <span class="text-sm font-normal text-sky-600">incluidas</span></p>
                        <p class="text-sm text-sky-700">Total ${{ number_format($resumen['compras_total'], 2) }}</p>
                        <p class="mt-1 text-xs text-sky-700/80">
                            Sin PDF: <span class="font-semibold {{ $resumen['compras_sin_pdf'] > 0 ? 'text-amber-700' : '' }}">{{ number_format($resumen['compras_sin_pdf']) }}</span>
                            · Sin JSON: <span class="font-semibold {{ $resumen['compras_sin_json'] > 0 ? 'text-amber-700' : '' }}">{{ number_format($resumen['compras_sin_json']) }}</span>
                        </p>
                    </div>
                    <div class="rounded-lg bg-green-50 ring-1 ring-green-200 p-4">
                        <p class="text-xs uppercase tracking-wide text-green-600">Ventas (emitidos)</p>
                        <p class="mt-1 text-2xl font-semibold text-green-800">{{ number_format($resumen['ventas_cantidad']) }} <span class="text-sm font-normal text-green-600">incluidas</span></p>
                        <p class="text-sm text-green-700">Total ${{ number_format($resumen['ventas_total'], 2) }}</p>
                        <p class="mt-1 text-xs text-green-700/80">
                            Sin JSON generado: <span class="font-semibold {{ $resumen['ventas_sin_json'] > 0 ? 'text-amber-700' : '' }}">{{ number_format($resumen['ventas_sin_json']) }}</span>
                        </p>
                    </div>
                </div>

                {{-- Destinatario configurado + último envío exitoso (leído del activity log; sin persistencia nueva). --}}
                <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-gray-100 pt-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Destinatario de contabilidad</dt>
                        <dd class="font-medium {{ $correoContabilidad ? 'text-gray-900' : 'text-gray-400' }}">{{ $correoContabilidad ?? 'No configurado' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Último envío del paquete</dt>
                        @if ($ultimoEnvio)
                            <dd class="font-medium text-gray-900">
                                {{ optional($ultimoEnvio['fecha'])->format('d/m/Y H:i') }}
                                @if ($ultimoEnvio['etiqueta']) · {{ $ultimoEnvio['etiqueta'] }} @endif
                            </dd>
                            <dd class="text-xs text-gray-500">
                                @if ($ultimoEnvio['correo']) a {{ $ultimoEnvio['correo'] }} @endif
                                @if ($ultimoEnvio['usuario']) · por {{ $ultimoEnvio['usuario'] }} @endif
                            </dd>
                        @else
                            <dd class="text-gray-400">Sin envíos anteriores</dd>
                        @endif
                    </div>
                </dl>
            </div>

            {{-- Generar --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Generar paquete</h3>
                <p class="mt-1 text-xs text-gray-500">
                    Genera <code>documentos_contabilidad_{{ $rango['etiqueta'] }}{{ $bloqueaCobertura ? '_INCOMPLETO' : '' }}.zip</code>
                    con los Excel de compras y ventas, los PDF/JSON de compras ya guardados y el PDF/JSON de cada venta
                    (documento emitido) del período. El período se arma por la <strong>fecha de emisión</strong> del documento,
                    no por la fecha del correo.
                </p>
                @if ($bloqueaCobertura)
                    {{-- La descarga NO se bloquea: es la forma de revisar qué hay mientras se
                         recupera lo que falta. Lo que no puede pasar es que se confunda con un
                         paquete cerrado, así que el aviso viaja en el nombre y en el LEEME. --}}
                    <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 dark:text-amber-300">
                        <p class="font-semibold">El envío a contabilidad está bloqueado: el período no está completo.</p>
                        <p class="mt-1">
                            Podés descargarlo igual para revisarlo: el ZIP sale marcado como <code>_INCOMPLETO</code> y el
                            <code>LEEME.txt</code> abre con los días que faltan. Para habilitar el envío, recuperá el período
                            desde Compras y volvé a generarlo.
                        </p>
                    </div>
                @endif
                {{-- Candado de correo real: solo aparece fuera de producción. --}}
                <div class="mt-3">
                    <x-correo-simulado-aviso />
                </div>
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
                                title="{{ $bloqueaCobertura ? 'El período de compras no está completo: recuperalo desde Compras.' : ($correoContabilidad === null ? 'Falta un correo de contabilidad válido (Configuración > Contabilidad).' : 'No hay documentos en el rango para las fuentes incluidas.') }}"
                                class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed">
                            Enviar a contabilidad
                        </button>
                    @endif
                </div>
                @unless ($puedeEnviar)
                    {{-- Este texto es lo único que explica POR QUÉ el botón está apagado, así que
                         tiene que leerse: `text-gray-400` daba 3.3:1 sobre el fondo oscuro. --}}
                    <p class="mt-2 text-xs text-gray-600 dark:text-paper-300">
                        @if ($bloqueaCobertura)
                            El envío está bloqueado hasta que el período de compras esté revisado por completo.
                            <a href="{{ route('documentos-recibidos.index') }}" class="text-indigo-600 hover:underline dark:text-indigo-300">Recuperá el período desde Compras</a>
                            y volvé a generarlo. Mientras tanto podés descargar el ZIP, que sale marcado como incompleto.
                        @elseif ($correoContabilidad === null)
                            Para habilitar el envío, configurá un correo de contabilidad válido en
                            <a href="{{ route('configuracion.contabilidad.edit') }}" class="text-indigo-600 hover:underline dark:text-indigo-300">Configuración &gt; Contabilidad</a>.
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
                            <p class="mt-1 text-sm text-gray-500">Revisá los datos. Se enviará un solo correo con el ZIP adjunto. Si el envío es exitoso, las compras pendientes incluidas en este rango se marcarán como "enviado" (no toca ignoradas ni ya enviadas). Las ventas no se modifican.</p>

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
