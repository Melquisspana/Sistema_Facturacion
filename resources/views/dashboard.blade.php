@php
    $colorEstado = ['ok' => 'bg-green-100 text-green-700', 'advertencia' => 'bg-amber-100 text-amber-700', 'critico' => 'bg-rose-100 text-rose-700'];
    $textoEstado = ['ok' => 'Todo en orden', 'advertencia' => 'Requiere atención', 'critico' => 'Atención inmediata'];
    $card = 'bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
    // El diagnóstico usa 'correcto' (no 'ok'); se traduce solo para reusar $colorEstado.
    $colorDiagnostico = ['correcto' => $colorEstado['ok'], 'advertencia' => $colorEstado['advertencia'], 'critico' => $colorEstado['critico']];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- A: Encabezado --}}
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-800 dark:text-paper-100">{{ $saludo }}, {{ auth()->user()->name }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">Resumen operativo de Dulces La Negrita &middot; {{ \Illuminate\Support\Str::ucfirst(now()->translatedFormat('l d \d\e F \d\e Y')) }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium {{ $colorEstado[$estadoGeneral] }}">
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    {{ $textoEstado[$estadoGeneral] }}
                </span>
            </div>

            {{-- B: Tarjetas principales --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @if ($veFacturacion)
                    <div class="{{ $card }}">
                        <p class="text-xs text-gray-400 dark:text-paper-500">DTE aceptados (mes)</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ number_format($stats['dte_aceptados_mes']) }}</p>
                    </div>
                    <div class="{{ $card }}">
                        <p class="text-xs text-gray-400 dark:text-paper-500">Ventas del mes</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">${{ number_format($stats['ventas_mes'], 2) }}</p>
                    </div>
                @endif
                @if ($veOperativos)
                    <div class="{{ $card }}">
                        <p class="text-xs text-gray-400 dark:text-paper-500">Compras pendientes</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ number_format($stats['documentos_pendientes']) }}</p>
                        <a href="{{ route('documentos-recibidos.index') }}" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">Ver compras</a>
                    </div>
                    <div class="{{ $card }}">
                        <p class="text-xs text-gray-400 dark:text-paper-500">Listas de empaque (mes)</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ number_format($stats['listas_recientes']) }}</p>
                        <a href="{{ route('exportaciones.index') }}" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">Ver listas</a>
                    </div>
                @endif
                @if ($esAdmin)
                    <div class="{{ $card }}">
                        <p class="text-xs text-gray-400 dark:text-paper-500">Jobs fallidos</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums {{ $stats['jobs_fallidos'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-800 dark:text-paper-100' }}">{{ number_format($stats['jobs_fallidos']) }}</p>
                        <a href="{{ route('admin.salud-sistema') }}" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">Salud del sistema</a>
                    </div>
                @endif
                <div class="{{ $card }}">
                    <p class="text-xs text-gray-400 dark:text-paper-500">Estado del sistema</p>
                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold {{ $estadoGeneral === 'ok' ? 'text-green-700 dark:text-green-400' : ($estadoGeneral === 'advertencia' ? 'text-amber-700 dark:text-amber-400' : 'text-rose-700 dark:text-rose-400') }}">
                        {{ $textoEstado[$estadoGeneral] }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {{-- C: Actividad reciente --}}
                <div class="lg:col-span-2 {{ $card }} !p-0 overflow-hidden">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-ink-700">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-300">Actividad reciente</h3>
                    </div>
                    @if ($veFacturacion && $actividad->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 dark:bg-ink-900 dark:text-paper-500">
                                        <th class="py-2.5 px-4">Tipo</th>
                                        <th class="py-2.5 px-4">Número de control</th>
                                        <th class="py-2.5 px-4">Cliente</th>
                                        <th class="py-2.5 px-4 text-right">Total</th>
                                        <th class="py-2.5 px-4 text-center">Estado</th>
                                        <th class="py-2.5 px-4 text-right">&nbsp;</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                    @foreach ($actividad as $dte)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                            <td class="py-2.5 px-4 text-gray-600 dark:text-paper-300">{{ $dte->tipo_dte->label() }}</td>
                                            <td class="py-2.5 px-4 font-mono text-xs text-gray-600 dark:text-paper-300">{{ $dte->numero_control ?? '—' }}</td>
                                            <td class="py-2.5 px-4 text-gray-800 dark:text-paper-100">{{ $dte->cliente?->nombre ?? '—' }}</td>
                                            <td class="py-2.5 px-4 text-right tabular-nums text-gray-700 dark:text-paper-100">${{ number_format((float) $dte->total_pagar, 2) }}</td>
                                            <td class="py-2.5 px-4 text-center"><x-estado-dte-badge :estado="$dte->estado" /></td>
                                            <td class="py-2.5 px-4 text-right">
                                                <a href="{{ route('facturacion.show', $dte) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Abrir</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif ($veFacturacion)
                        <p class="px-4 py-8 text-center text-sm text-gray-400 dark:text-paper-500">Todavía no hay documentos enviados o aceptados este período.</p>
                    @else
                        <p class="px-4 py-8 text-center text-sm text-gray-400 dark:text-paper-500">No tenés acceso a Facturación.</p>
                    @endif
                </div>

                <div class="space-y-6">
                    {{-- D: Acciones rápidas --}}
                    <div class="{{ $card }}">
                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-300">Acciones rápidas</h3>
                        <div class="flex flex-col gap-2">
                            @if ($esGestorDte)
                                <a href="{{ route('facturacion.create-ccf') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-400">Nuevo CCF</a>
                                <a href="{{ route('facturacion.create-factura') }}" class="rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100 dark:hover:bg-ink-600">Nueva Factura</a>
                            @endif
                            @if ($veOperativos)
                                <a href="{{ route('exportaciones.create') }}" class="rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100 dark:hover:bg-ink-600">Nueva lista de empaque</a>
                                <a href="{{ route('documentos-recibidos.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100 dark:hover:bg-ink-600">Compras</a>
                                <a href="{{ route('ppq.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100 dark:hover:bg-ink-600">Buscar CCF / NC</a>
                            @endif
                            @unless ($esGestorDte || $veOperativos)
                                <p class="text-sm text-gray-400 dark:text-paper-500">No hay acciones disponibles para tu rol.</p>
                            @endunless
                        </div>
                    </div>

                    {{-- E: Estado técnico compacto --}}
                    @if ($estadoTecnico)
                        <div class="{{ $card }}">
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-300">Estado técnico</h3>
                            <dl class="space-y-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Ambiente</dt>
                                    <dd class="font-medium text-gray-800 dark:text-paper-100">{{ $estadoTecnico['ambiente'] }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Punto de venta</dt>
                                    <dd class="font-medium text-gray-800 dark:text-paper-100">{{ $estadoTecnico['punto_venta_predeterminado'] }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Dry-run</dt>
                                    <dd>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $estadoTecnico['dry_run'] ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700' }}">
                                            {{ $estadoTecnico['dry_run'] ? 'ACTIVO' : 'INACTIVO' }}
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Worker</dt>
                                    <dd class="font-medium text-gray-800 dark:text-paper-100">
                                        {{ match($estadoTecnico['worker_estado']) { 'activo' => 'Activo', 'inactivo' => 'Inactivo', default => 'Sin datos' } }}
                                        @if ($estadoTecnico['worker_hace'])
                                            <span class="text-xs text-gray-400 dark:text-paper-500">({{ $estadoTecnico['worker_hace'] }})</span>
                                        @endif
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Jobs pendientes / fallidos</dt>
                                    <dd class="font-medium tabular-nums text-gray-800 dark:text-paper-100">{{ $estadoTecnico['jobs_pendientes'] }} / {{ $estadoTecnico['jobs_fallidos'] }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-gray-500 dark:text-paper-300">Firma</dt>
                                    <dd class="font-medium text-gray-800 dark:text-paper-100">{{ $estadoTecnico['firma_mock'] ? 'Mock (prueba)' : 'Real' }}</dd>
                                </div>
                            </dl>
                            @if ($estadoTecnico['modo'])
                                <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-ink-700 dark:text-paper-500">{{ $estadoTecnico['modo']['detalle'] }}</p>
                            @endif
                        </div>
                    @endif

                    {{-- F: Diagnóstico del sistema (por qué el badge de arriba dice lo que dice) --}}
                    @if ($diagnostico)
                        <div class="{{ $card }}">
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-paper-300">Diagnóstico</h3>
                            <ul class="space-y-2 text-sm">
                                @foreach ($diagnostico['checks'] as $c)
                                    <li class="flex items-start justify-between gap-2">
                                        <span class="text-gray-600 dark:text-paper-300">{{ $c['label'] }}</span>
                                        <span class="shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $colorDiagnostico[$c['nivel']] ?? $colorDiagnostico['advertencia'] }}"
                                              title="{{ $c['detalle'] }}">
                                            {{ ucfirst($c['nivel']) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
