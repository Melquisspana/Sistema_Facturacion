@php
    // Inicio (rediseño del 13/11/2026, pedido del dueño: «más bonito, también para el
    // teléfono»). Arriba las ventas del mes en números grandes; después los accesos que el
    // permiso deja; luego los últimos documentos y las rutas. Lo técnico va al final,
    // plegado y solo para Administrador; a Facturación solo le llega un aviso cuando
    // algo es crítico. Mismos datos, rutas y permisos que antes.
    $usuarioActual = auth()->user();
    // Jefatura (13/11/2026): sin número de control ni estado del DTE; fecha y el PDF, como en
    // Documentos fiscales (el controlador ya le trae solo lo procesado por Hacienda).
    $soloPdf = (bool) $usuarioActual?->abreDocumentosComoPdf();

    $colorEstado = ['ok' => 'bg-green-100 text-green-700', 'advertencia' => 'bg-amber-100 text-amber-700', 'critico' => 'bg-red-100 text-red-700'];
    // En desarrollo una «advertencia» es el estado seguro esperado (transmisión
    // deshabilitada / dry-run): celeste informativo, no ámbar. En producción, ámbar.
    if ($estadoGeneral === 'advertencia' && config('app.env') !== 'production') {
        $colorEstado['advertencia'] = 'bg-sky-50 text-sky-700';
    }
    // El diagnóstico usa 'correcto' (no 'ok'); se traduce solo para reusar los colores.
    $colorDiagnostico = ['correcto' => 'bg-green-100 text-green-700', 'advertencia' => 'bg-amber-100 text-amber-700', 'critico' => 'bg-red-100 text-red-700'];

    $caja = 'rounded-lg border border-gray-200 bg-white';
    // Los botones compartidos actuales solo generan <button>; estos accesos son enlaces.
    $acceso = 'inline-flex min-h-11 items-center justify-center rounded-md px-3 py-2 text-center text-sm font-medium focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
    $accesoSecundario = $acceso.' border border-gray-300 bg-white text-gray-700 hover:bg-gray-50';

    // Accesos rápidos: los mismos permisos que la barra lateral y que cada ruta.
    $accesos = array_values(array_filter([
        $veFacturacion && $usuarioActual?->can('create', \App\Models\Dte::class) ? ['Nuevo CCF', route('facturacion.create-ccf'), true] : null,
        $veFacturacion && $usuarioActual?->can('create', \App\Models\Dte::class) ? ['Nueva Factura', route('facturacion.create-factura'), false] : null,
        $veFacturacion ? ['Llegó de pedidos', route('facturacion.llego-de-fabrica'), false] : null,
        $veFacturacion ? ['Documentos fiscales', route('facturacion.index'), false] : null,
        $usuarioActual?->can('ppq.ver') ? ['Facturas por cobrar', route('cobros.index'), false] : null,
        $usuarioActual?->can('rutas.ver') ? ['Rutas', route('rutas.dashboard'), false] : null,
        $veOperativos ? ['Compras', route('documentos-recibidos.index'), false] : null,
        $veFacturacion && $usuarioActual?->can('exportaciones.ver') && $usuarioActual?->can('exportaciones.gestionar') ? ['Nueva lista de empaque', route('facturacion.listas.create'), false] : null,
    ]));
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="break-words text-xl font-semibold leading-tight text-gray-800">{{ $saludo }}, {{ $usuarioActual->name }}</h1>
                <p class="mt-1 text-sm text-gray-500">Resumen operativo de {{ \App\Support\NombreEmpresa::actual() }} &middot; {{ \Illuminate\Support\Str::ucfirst(now()->locale('es')->translatedFormat('l d \d\e F \d\e Y')) }}</p>
            </div>
            @if ($esAdmin && $estadoGeneral !== 'critico')
                {{-- Chiquito y solo para quien administra: el detalle está plegado al final. --}}
                <a href="#estado-sistema"
                   class="inline-flex min-h-11 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium {{ $colorEstado[$estadoGeneral] }}">
                    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                    {{ $textoEstadoGeneral }}
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Aviso cuando algo falla de verdad (solo quien emite). --}}
            @if ($esGestorDte && $estadoGeneral === 'critico')
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    <p class="font-semibold">Atención inmediata</p>
                    <p class="mt-0.5">{{ $motivoEstadoGeneral }}.</p>
                    @if ($esAdmin)
                        <a href="#estado-sistema" class="mt-1 inline-flex min-h-11 items-center font-medium underline">Ver el estado del sistema</a>
                    @else
                        <p class="mt-0.5">Avisale al administrador.</p>
                    @endif
                </div>
            @endif

            {{-- El mes es la cifra principal; hoy y los DTE son secundarios. --}}
            @if ($veFacturacion || $veOperativos)
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    @if ($veFacturacion)
                        <section aria-labelledby="titulo-ventas" class="rounded-xl bg-indigo-600 p-5 text-white shadow-sm sm:p-6 lg:col-span-2">
                            <h2 id="titulo-ventas" class="text-sm font-medium opacity-80">Ventas del mes</h2>
                            <p class="mt-1 break-words text-3xl font-bold tabular-nums tracking-tight">${{ number_format($stats['ventas_mes'], 2) }}</p>
                            <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-indigo-400 pt-4">
                                <div>
                                    <dt class="text-xs opacity-80">Vendido hoy</dt>
                                    <dd class="mt-0.5 break-words text-xl font-semibold tabular-nums">${{ number_format($stats['ventas_hoy'] ?? 0, 2) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs opacity-80">DTE aceptados (mes)</dt>
                                    <dd class="mt-0.5 break-words text-xl font-semibold tabular-nums">{{ number_format($stats['dte_aceptados_mes']) }}</dd>
                                </div>
                            </dl>
                        </section>
                    @endif

                    @if ($veOperativos)
                        <div class="grid grid-cols-2 gap-4 {{ $veFacturacion ? '' : 'lg:col-span-3 lg:grid-cols-2' }}">
                            <a href="{{ route('documentos-recibidos.index', ['vista' => 'pendientes', 'rango' => 'todos']) }}"
                               class="{{ $caja }} block p-4 hover:border-indigo-300">
                                <span class="block text-sm text-gray-500">Compras pendientes</span>
                                <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($stats['documentos_pendientes']) }}</span>
                                <span class="mt-1 block text-sm font-medium text-indigo-600">Ver compras</span>
                            </a>
                            @if ($veFacturacion && $usuarioActual->can('exportaciones.ver'))
                                <a href="{{ route('facturacion.listas.index') }}"
                                   class="{{ $caja }} block p-4 hover:border-indigo-300">
                                    <span class="block text-sm text-gray-500">Listas de empaque (mes)</span>
                                    <span class="mt-1 block text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($stats['listas_recientes']) }}</span>
                                    <span class="mt-1 block text-sm font-medium text-indigo-600">Ver listas</span>
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- Sin cargar las bandejas ni calcular saldos: cada tarjeta abre su lista vigente. --}}
            @if ($usuarioActual->can('ppq.ver') || $usuarioActual->canAny(['rutas.ver', 'rutas.entregas.registrar']))
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @can('ppq.ver')
                        <a href="{{ route('cobros.index') }}" class="{{ $caja }} block min-h-11 p-5 hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <h2 class="text-xl font-semibold text-gray-800">Por cobrar</h2>
                            <p class="mt-1 text-sm text-gray-500">Revisá las facturas y sus pagos.</p>
                            <span class="mt-3 block text-sm font-medium text-indigo-600">Facturas por cobrar &rarr;</span>
                        </a>
                    @endcan
                    @canany(['rutas.ver', 'rutas.entregas.registrar'])
                        <a href="{{ route('rutas.por-entregar.index') }}" class="{{ $caja }} block min-h-11 p-5 hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <h2 class="text-xl font-semibold text-gray-800">Por entregar</h2>
                            <p class="mt-1 text-sm text-gray-500">Consultá los CCF que faltan entregar.</p>
                            <span class="mt-3 block text-sm font-medium text-indigo-600">Ver pendientes &rarr;</span>
                        </a>
                    @endcanany
                </div>
            @endif

            {{-- Accesos rápidos: solo lo que el permiso de cada uno deja abrir. --}}
            @if ($accesos !== [])
                <nav aria-labelledby="titulo-accesos">
                    <h2 id="titulo-accesos" class="mb-2 text-sm font-semibold text-gray-700">Accesos rápidos</h2>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($accesos as [$texto, $url, $principal])
                            <a href="{{ $url }}"
                               class="{{ $principal ? $acceso.' bg-indigo-600 font-semibold text-white hover:bg-indigo-700' : $accesoSecundario }}">{{ $texto }}</a>
                        @endforeach
                    </div>
                </nav>
            @endif

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                {{-- Últimos documentos que pasaron por Hacienda (producción, aceptados). La
                     cantidad viene acotada desde la consulta; no hay scroll vertical interno.
                     Escritorio: tabla. Celular (debajo de md): tarjetas, como Documentos fiscales. --}}
                @if ($veFacturacion)
                    <section aria-labelledby="titulo-documentos" class="{{ $caja }} overflow-hidden {{ ! empty($avisoRutas) || $diagnostico ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
                            <div>
                                <h2 id="titulo-documentos" class="text-base font-semibold text-gray-800">Últimos documentos</h2>
                                <p class="text-xs text-gray-500">Documentos reales de producción</p>
                            </div>
                            <a href="{{ route('facturacion.index') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-600 hover:underline">Ver todos</a>
                        </div>

                        @if ($actividad->isNotEmpty())
                            <div class="hidden md:block overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                            @if ($soloPdf)
                                                <th class="px-4 py-2.5">Fecha</th>
                                                <th class="px-4 py-2.5">Tipo</th>
                                            @else
                                                <th class="px-4 py-2.5">Tipo / Número de control</th>
                                                <th class="px-4 py-2.5">Fecha</th>
                                            @endif
                                            <th class="px-4 py-2.5">Cliente / sala</th>
                                            <th class="px-4 py-2.5 text-right">Total</th>
                                            @unless ($soloPdf)
                                                <th class="px-4 py-2.5">Estado</th>
                                            @endunless
                                            <th class="px-4 py-2.5 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($actividad as $dte)
                                            <tr class="hover:bg-gray-50">
                                                @if ($soloPdf)
                                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600">{{ $dte->fecha_emision?->format('d/m/Y') }}</td>
                                                    <td class="px-4 py-2.5 text-gray-700">{{ $dte->tipo_dte->label() }}</td>
                                                @else
                                                    <td class="px-4 py-2.5">
                                                        <div class="text-gray-800">{{ $dte->tipo_dte->label() }}</div>
                                                        <div class="font-mono text-xs text-gray-500">{{ $dte->numero_control ?? '—' }}</div>
                                                    </td>
                                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600">{{ $dte->fecha_emision?->format('d/m/Y') }}</td>
                                                @endif
                                                {{-- Cliente fiscal + sala/sucursal, igual que el listado de Facturación y el PDF. --}}
                                                <td class="px-4 py-2.5">
                                                    <div class="font-medium text-gray-800">{{ $dte->cliente?->nombre ?? '—' }}</div>
                                                    @if ($dte->clienteSucursal)
                                                        <div class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</div>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2.5 text-right whitespace-nowrap tabular-nums font-medium text-gray-800">${{ number_format((float) $dte->total_pagar, 2) }}</td>
                                                @unless ($soloPdf)
                                                    <td class="px-4 py-2.5"><x-estado-dte-badge :estado="$dte->estado" /></td>
                                                @endunless
                                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                                    @if ($soloPdf)
                                                        <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank" class="text-indigo-600 hover:underline">Ver PDF</a>
                                                    @else
                                                        <a href="{{ route('facturacion.show', $dte) }}" class="text-indigo-600 hover:underline">Abrir</a>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <ul class="md:hidden divide-y divide-gray-100">
                                @foreach ($actividad as $dte)
                                    <li class="px-4 py-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-semibold text-gray-900">{{ $dte->tipo_dte->label() }}</p>
                                                <p class="break-words font-medium text-gray-800">{{ $dte->cliente?->nombre ?? '—' }}</p>
                                                @if ($dte->clienteSucursal)
                                                    <p class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</p>
                                                @endif
                                            </div>
                                            <span class="break-words font-semibold tabular-nums text-gray-900">${{ number_format((float) $dte->total_pagar, 2) }}</span>
                                        </div>
                                        @unless ($soloPdf)
                                            <p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $dte->numero_control ?? '—' }}</p>
                                        @endunless
                                        <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                                            <span class="flex items-center gap-2 text-gray-600">
                                                {{ $dte->fecha_emision?->format('d/m/Y') }}
                                                @unless ($soloPdf)
                                                    <x-estado-dte-badge :estado="$dte->estado" />
                                                @endunless
                                            </span>
                                            @if ($soloPdf)
                                                <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank"
                                                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-indigo-700 hover:bg-gray-50">Ver PDF</a>
                                            @else
                                                <a href="{{ route('facturacion.show', $dte) }}"
                                                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-indigo-700 hover:bg-gray-50">Abrir</a>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="px-4 py-10 text-center text-sm text-gray-500">Todavía no hay documentos enviados o aceptados este período.</p>
                        @endif
                    </section>
                @endif

                @if (! empty($avisoRutas) || $diagnostico)
                    <div class="space-y-6 {{ $veFacturacion ? '' : 'lg:col-span-3' }}">
                        {{-- Rutas que ya toca visitar (o están por tocar), con sus CCF por entregar. --}}
                        @if (! empty($avisoRutas))
                            <section aria-labelledby="titulo-rutas" class="rounded-lg border border-amber-200 bg-amber-50">
                                <div class="flex items-center justify-between gap-2 px-4 pt-3">
                                    <h2 id="titulo-rutas" class="text-base font-semibold text-amber-800">Rutas por visitar</h2>
                                    <a href="{{ route('rutas.dashboard') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-600 hover:underline">Ver rutas</a>
                                </div>
                                <ul class="px-4 pb-2">
                                    @foreach ($avisoRutas as $aviso)
                                        @php $atrasada = $aviso['estado'] === \App\Services\Rutas\RitmoRutas::ATRASADA; @endphp
                                        <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                            <span class="min-w-0">
                                                <span class="block font-semibold text-gray-800">{{ $aviso['ruta'] }}</span>
                                                <span class="block {{ $atrasada ? 'font-medium text-red-700' : 'text-amber-800' }}">{{ $aviso['texto'] }}</span>
                                            </span>
                                            @if ($aviso['ccf'] > 0)
                                                <span class="shrink-0 rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-gray-700">{{ $aviso['ccf'] }} CCF</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        {{-- Estado del sistema: solo Administrador, plegado (abierto si algo es crítico). --}}
                        @if ($diagnostico)
                            <details id="estado-sistema" class="{{ $caja }} group" @if ($estadoGeneral === 'critico') open @endif>
                                <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-2 px-4 py-2 text-sm font-semibold text-gray-700">
                                    Estado del sistema
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium {{ $colorEstado[$estadoGeneral] }}">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                        {{-- Con un problema crítico el aviso grande ya dice «Atención inmediata»: acá no se repite. --}}
                                        {{ $estadoGeneral === 'critico' ? 'Con problemas' : $textoEstadoGeneral }}
                                    </span>
                                </summary>
                                <div class="space-y-4 border-t border-gray-100 px-4 py-3 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-gray-600">Trabajos fallidos</span>
                                        <span class="font-semibold tabular-nums {{ $estadoTecnico['jobs_fallidos'] > 0 ? 'text-red-700' : 'text-gray-800' }}">{{ number_format($estadoTecnico['jobs_fallidos']) }}</span>
                                    </div>

                                    @if ($estadoTecnico)
                                        <div>
                                            <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Estado técnico</h3>
                                            <dl class="space-y-1.5">
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Ambiente</dt>
                                                    <dd class="font-medium text-gray-800">{{ $estadoTecnico['ambiente'] }}</dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Punto de venta</dt>
                                                    <dd class="text-right font-medium text-gray-800">{{ $estadoTecnico['punto_venta_predeterminado'] }}</dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Dry-run</dt>
                                                    <dd>
                                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $estadoTecnico['dry_run'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                                            {{ $estadoTecnico['dry_run'] ? 'ACTIVO' : 'INACTIVO' }}
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Worker</dt>
                                                    <dd class="text-right font-medium text-gray-800">
                                                        {{ match($estadoTecnico['worker_estado']) { 'activo' => 'Activo', 'inactivo' => 'Inactivo', default => 'Sin datos' } }}
                                                        @if ($estadoTecnico['worker_hace'])
                                                            <span class="text-xs font-normal text-gray-500">({{ $estadoTecnico['worker_hace'] }})</span>
                                                        @endif
                                                    </dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Jobs pendientes / fallidos</dt>
                                                    <dd class="font-medium tabular-nums text-gray-800">{{ $estadoTecnico['jobs_pendientes'] }} / {{ $estadoTecnico['jobs_fallidos'] }}</dd>
                                                </div>
                                                <div class="flex items-center justify-between gap-2">
                                                    <dt class="text-gray-600">Firma</dt>
                                                    <dd class="font-medium text-gray-800">{{ $estadoTecnico['firma_mock'] ? 'Mock (prueba)' : 'Real' }}</dd>
                                                </div>
                                            </dl>
                                            @if ($estadoTecnico['modo'])
                                                <p class="mt-2 text-xs text-gray-500">{{ $estadoTecnico['modo']['detalle'] }}</p>
                                            @endif
                                        </div>
                                    @endif

                                    <div>
                                        <h3 class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Diagnóstico</h3>
                                        <ul class="space-y-1.5">
                                            @foreach ($diagnostico['checks'] as $c)
                                                <li class="flex items-start justify-between gap-2">
                                                    <span class="text-gray-600">{{ $c['label'] }}</span>
                                                    <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $colorDiagnostico[$c['nivel']] ?? $colorDiagnostico['advertencia'] }}"
                                                          title="{{ $c['detalle'] }}">
                                                        {{ ucfirst($c['nivel']) }}
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <a href="{{ route('admin.salud-sistema') }}" class="inline-flex min-h-11 items-center font-medium text-indigo-600 hover:underline">Ver el detalle en Salud del sistema</a>
                                </div>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
