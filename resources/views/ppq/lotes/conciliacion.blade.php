<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Conciliación TXT — Lote PPQ #{{ $lote->id }}</h2>
            <a href="{{ route('ppq.lotes.show', $lote) }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">← Volver al lote</a>
        </div>
    </x-slot>

    @php
        $t = $reporte['totales'];
        $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
        $dmy = fn ($f) => \App\Support\Fecha::dmy($f) ?: '—';
        $ctrl = fn ($item) => $item->numero_control ?? $item->dte?->numero_control ?: '—';
    @endphp

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="rounded-md bg-indigo-50 border border-indigo-200 p-3 text-sm text-indigo-800">
                Archivo <span class="font-mono">{{ $archivo }}</span> — {{ $totalFilas }} fila(s) leída(s) del TXT de Calleja.
                Los CCF se marcan como <strong>pagados</strong> solo si aparecen en el TXT (tipo CF); estar en el PPQ no los marca pagados.
            </div>

            {{-- Resumen de totales (según TXT) --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total CCF pagado</div>
                    <div class="mt-1 text-2xl font-bold text-green-700">{{ $money($t['total_ccf_pagado']) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ $t['cantidad_ccf_pagados'] }} del PPQ conciliados</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total NC descontado</div>
                    <div class="mt-1 text-2xl font-bold text-rose-600">{{ $money($t['total_nc_descontado']) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ $t['cantidad_nc_aplicadas'] }} aplicadas</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Ajustes QD / PPQ</div>
                    <div class="mt-1 text-2xl font-bold text-amber-600">{{ $money($t['total_qd']) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ $t['cantidad_qd'] }} ajuste(s)</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-indigo-200 rounded-xl p-4 bg-indigo-50/40">
                    <div class="text-xs text-gray-500">Neto final (TXT)</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $money($t['neto_final']) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">CCF − NC − QD</div>
                </div>
            </div>

            {{-- Conteos rápidos --}}
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-green-100 text-green-700 px-3 py-1">CCF pagados: {{ $t['cantidad_ccf_pagados'] }}</span>
                <span class="rounded-full bg-amber-100 text-amber-700 px-3 py-1">CCF pendientes: {{ $t['cantidad_ccf_pendientes'] }}</span>
                <span class="rounded-full bg-indigo-100 text-indigo-700 px-3 py-1">NC aplicadas: {{ $t['cantidad_nc_aplicadas'] }}</span>
                <span class="rounded-full bg-rose-100 text-rose-700 px-3 py-1">NC pendientes: {{ $t['cantidad_nc_pendientes'] }}</span>
                <span class="rounded-full bg-gray-100 text-gray-600 px-3 py-1">En TXT, no en PPQ: {{ $t['cantidad_no_en_ppq'] }}</span>
            </div>

            {{-- CCF del PPQ pagados según TXT --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200"><h3 class="text-sm font-semibold text-gray-700">CCF del PPQ pagados según TXT ({{ count($reporte['ccfPagados']) }})</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                            <th class="py-2.5 px-3">N° de control</th>
                            <th class="py-2.5 px-3">Estado</th>
                            <th class="py-2.5 px-3">Fecha de pago</th>
                            <th class="py-2.5 px-3 text-right">Monto sistema</th>
                            <th class="py-2.5 px-3 text-right">Monto TXT</th>
                            <th class="py-2.5 px-3 text-right">Diferencia</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($reporte['ccfPagados'] as $p)
                                @php $dif = $p['diferencia']; $alerta = $dif !== null && abs((float) $dif) >= 0.01; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $ctrl($p['item']) }}</td>
                                    <td class="py-2 px-3"><span class="rounded-full bg-green-100 text-green-700 px-2 py-0.5 text-[11px] font-medium">Pagado / conciliado</span></td>
                                    <td class="py-2 px-3 text-gray-700">{{ $dmy($p['fecha']) }}</td>
                                    <td class="py-2 px-3 text-right text-gray-800">{{ $money($p['item']->monto_dte) }}</td>
                                    <td class="py-2 px-3 text-right text-gray-800">{{ $p['monto_txt'] !== null ? $money($p['monto_txt']) : '—' }}</td>
                                    <td class="py-2 px-3 text-right {{ $alerta ? 'text-red-600 font-semibold' : 'text-gray-400' }}">{{ $dif !== null ? $money($dif) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-8 text-center text-gray-400">Ningún CCF del PPQ aparece pagado en el TXT.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- CCF del PPQ pendientes (no aparecen en el TXT) --}}
            @if (count($reporte['ccfPendientes']))
                <div class="bg-white shadow-sm ring-1 ring-amber-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-amber-200 bg-amber-50">
                        <h3 class="text-sm font-semibold text-amber-800">CCF del PPQ pendientes de conciliación ({{ count($reporte['ccfPendientes']) }})</h3>
                        <p class="text-xs text-amber-700 mt-0.5">Están agregados al PPQ pero NO aparecen en el TXT de Calleja: siguen pendientes / no pagados.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">N° de control</th>
                                <th class="py-2.5 px-3 text-right">Monto sistema</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reporte['ccfPendientes'] as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $ctrl($item) }}</td>
                                        <td class="py-2 px-3 text-right text-gray-800">{{ $money($item->monto_dte) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- NC del PPQ aplicadas según TXT --}}
            @if (count($reporte['ncAplicadas']) || count($reporte['ncPendientes']))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200"><h3 class="text-sm font-semibold text-gray-700">Notas de crédito del PPQ ({{ count($reporte['ncAplicadas']) }} aplicadas, {{ count($reporte['ncPendientes']) }} pendientes)</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">N° de control</th>
                                <th class="py-2.5 px-3">Estado</th>
                                <th class="py-2.5 px-3">Fecha</th>
                                <th class="py-2.5 px-3 text-right">Monto sistema</th>
                                <th class="py-2.5 px-3 text-right">Monto TXT</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reporte['ncAplicadas'] as $p)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $ctrl($p['item']) }}</td>
                                        <td class="py-2 px-3"><span class="rounded-full bg-indigo-100 text-indigo-700 px-2 py-0.5 text-[11px] font-medium">Descontada / aplicada</span></td>
                                        <td class="py-2 px-3 text-gray-700">{{ $dmy($p['fecha']) }}</td>
                                        <td class="py-2 px-3 text-right text-rose-600">−{{ $money($p['item']->monto_dte) }}</td>
                                        <td class="py-2 px-3 text-right text-rose-600">{{ $p['monto_txt'] !== null ? '−'.$money($p['monto_txt']) : '—' }}</td>
                                    </tr>
                                @endforeach
                                @foreach ($reporte['ncPendientes'] as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $ctrl($item) }}</td>
                                        <td class="py-2 px-3"><span class="rounded-full bg-gray-100 text-gray-500 px-2 py-0.5 text-[11px] font-medium">Pendiente / no encontrada</span></td>
                                        <td class="py-2 px-3 text-gray-400">—</td>
                                        <td class="py-2 px-3 text-right text-rose-600">−{{ $money($item->monto_dte) }}</td>
                                        <td class="py-2 px-3 text-right text-gray-400">—</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Ajustes QD / PPQ --}}
            @if (count($reporte['ajustesQd']))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200"><h3 class="text-sm font-semibold text-gray-700">Ajustes / descuentos PPQ (QD) ({{ count($reporte['ajustesQd']) }})</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">N° PPQ / documento</th>
                                <th class="py-2.5 px-3">Fecha</th>
                                <th class="py-2.5 px-3 text-right">Monto TXT</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reporte['ajustesQd'] as $q)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $q['numero'] ?: '—' }}</td>
                                        <td class="py-2 px-3 text-gray-700">{{ $dmy($q['fecha']) }}</td>
                                        <td class="py-2 px-3 text-right text-amber-700">{{ $money($q['valor']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Documentos del TXT que no están en el PPQ --}}
            @if (count($reporte['noEnPpq']))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200"><h3 class="text-sm font-semibold text-gray-700">En el TXT pero NO agregados al PPQ ({{ count($reporte['noEnPpq']) }})</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">Tipo</th>
                                <th class="py-2.5 px-3">N° de documento</th>
                                <th class="py-2.5 px-3">Fecha</th>
                                <th class="py-2.5 px-3 text-right">Monto TXT</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reporte['noEnPpq'] as $e)
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 px-3 text-xs font-medium {{ $e['tipo'] === 'NC' ? 'text-rose-600' : 'text-gray-600' }}">{{ $e['tipo'] === 'NC' ? 'NC' : 'CCF' }}</td>
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700">{{ $e['numero'] ?: '—' }}</td>
                                        <td class="py-2 px-3 text-gray-700">{{ $dmy($e['fecha']) }}</td>
                                        <td class="py-2 px-3 text-right text-gray-700">{{ $e['valor'] !== null ? $money($e['valor']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="px-5 py-2 text-xs text-gray-400">Calleja pagó/aplicó estos documentos pero no están en este PPQ. Verificá si deberían agregarse.</p>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
