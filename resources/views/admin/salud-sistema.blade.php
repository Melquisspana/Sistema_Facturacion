<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Salud del sistema</h2>
    </x-slot>

    @php
        // Clases literales para el JIT de Tailwind (no interpolar).
        $badge = [
            'ok' => 'bg-green-100 text-green-700',
            'advertencia' => 'bg-amber-100 text-amber-700',
            'critico' => 'bg-rose-100 text-rose-700',
            'info' => 'bg-gray-100 text-gray-600',
        ];
        $badgeTexto = ['ok' => 'OK', 'advertencia' => 'Advertencia', 'critico' => 'Crítico', 'info' => 'Info'];
        $bannerClase = [
            'ok' => 'bg-green-50 border-green-300 text-green-800',
            'advertencia' => 'bg-amber-50 border-amber-300 text-amber-800',
            'critico' => 'bg-rose-50 border-rose-300 text-rose-800',
        ];
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Estado general --}}
            <div class="rounded-lg border p-5 {{ $bannerClase[$general['estado']] }}">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wide opacity-70">Estado general</p>
                        <p class="text-2xl font-bold">{{ $general['texto'] }}</p>
                    </div>
                    <span class="inline-flex px-3 py-1 rounded-full text-sm font-semibold {{ $badge[$general['estado']] }}">
                        {{ $badgeTexto[$general['estado']] }}
                    </span>
                </div>
                <p class="text-xs mt-2 opacity-70">Panel de solo lectura. No modifica datos ni cálculos. No muestra secretos (.env / claves).</p>
            </div>

            {{-- Seguridad --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-4">Seguridad</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($seguridad as $c)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-500">{{ $c['label'] }}</span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$c['estado']] }}">{{ $badgeTexto[$c['estado']] }}</span>
                            </div>
                            <div class="mt-1 font-mono text-gray-800 break-all">{{ $c['valor'] }}</div>
                            <p class="text-xs text-gray-400 mt-1">{{ $c['detalle'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Diagnóstico operativo (BD, worker, jobs, backup, firmador, transmisión, ambiente/PV, correlativos P002, storage, migraciones) --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                    <h3 class="font-semibold text-gray-700">Diagnóstico operativo</h3>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$diagnostico['nivel'] === 'correcto' ? 'ok' : $diagnostico['nivel']] }}">
                        {{ $badgeTexto[$diagnostico['nivel'] === 'correcto' ? 'ok' : $diagnostico['nivel']] }}
                    </span>
                </div>
                <p class="text-xs text-gray-400 mb-4">Mismo diagnóstico que el Dashboard — nunca hace red (BD/config/cache). Pasá el mouse sobre cada estado para ver el detalle.</p>
                <ul class="divide-y divide-gray-100">
                    @foreach ($diagnostico['checks'] as $c)
                        @php $nivelVista = $c['nivel'] === 'correcto' ? 'ok' : $c['nivel']; @endphp
                        <li class="flex items-start justify-between gap-2 py-2 text-sm">
                            <span class="text-gray-700">{{ $c['label'] }}</span>
                            <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$nivelVista] }}" title="{{ $c['detalle'] }}">
                                {{ $badgeTexto[$nivelVista] }}
                            </span>
                        </li>
                        <li class="pb-2 -mt-1 text-xs text-gray-400">{{ $c['detalle'] }}</li>
                    @endforeach
                </ul>
            </div>

            {{-- Transmisión DTE / modo de operación --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-semibold text-gray-700">Transmisión DTE (modo de operación)</h3>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$transmisionDte['color']] }}">{{ $transmisionDte['etiqueta'] }}</span>
                </div>
                <p class="text-sm text-gray-700">{{ $transmisionDte['detalle'] }}</p>
                @if ($transmisionDte['transmision_real_posible'] && $transmisionDte['candados']['flags']['modo_operacion'] !== 'principal')
                    <p class="text-sm font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-md p-3 mt-3">
                        ⚠ Producción habilitada desde un modo no principal. Revisá la configuración operativa.
                    </p>
                @elseif ($transmisionDte['transmision_real_posible'])
                    <p class="text-sm font-semibold text-green-700 bg-green-50 border border-green-200 rounded-md p-3 mt-3">
                        Producción activa. Este es el sistema principal y está listo para emitir DTE reales.
                    </p>
                @elseif ($transmisionDte['apitest_posible'])
                    <p class="text-sm font-semibold text-amber-800 bg-amber-50 border border-amber-200 rounded-md p-3 mt-3">
                        Transmisión al ambiente de <strong>PRUEBAS (apitest)</strong> habilitada: envía a Hacienda de
                        pruebas, <strong>NO a producción</strong>. Fuera del piloto conviene apagarla
                        (<span class="font-mono">DTE_TRANSMISION_TEST_ENABLED=false</span>).
                    </p>
                @else
                    <p class="text-xs text-green-700 mt-2">Modo paralelo seguro: este sistema no transmite producción (solo genera JSON, firma local y dry-run).</p>
                @endif
                <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div><dt class="text-gray-500">Modo</dt><dd class="font-mono text-gray-800">{{ $transmisionDte['candados']['flags']['modo_operacion'] }}</dd></div>
                    <div><dt class="text-gray-500">Transmisión habilitada</dt><dd class="font-mono text-gray-800">{{ $transmisionDte['candados']['flags']['enabled'] ? 'sí' : 'no' }}</dd></div>
                    <div><dt class="text-gray-500">Dry-run</dt><dd class="font-mono text-gray-800">{{ $transmisionDte['candados']['flags']['dry_run'] ? 'sí' : 'no' }}</dd></div>
                    <div><dt class="text-gray-500">Confirmación real</dt><dd class="font-mono text-gray-800">{{ $transmisionDte['candados']['flags']['real_confirmation'] ? 'sí' : 'no' }}</dd></div>
                </dl>
                <div class="mt-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Modo prueba / mock (no transmite/firma de verdad)</p>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="inline-flex px-2 py-0.5 rounded-full {{ $transmisionDte['mocks']['firma'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500' }}">Firma: {{ $transmisionDte['mocks']['firma'] ? 'MOCK' : 'apagado' }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded-full {{ $transmisionDte['mocks']['transmision'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500' }}">Transmisión: {{ $transmisionDte['mocks']['transmision'] ? 'MOCK' : 'apagado' }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded-full {{ $transmisionDte['mocks']['invalidacion'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500' }}">Invalidación: {{ $transmisionDte['mocks']['invalidacion'] ? 'MOCK' : 'apagado' }}</span>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-3">Solo lectura: no transmite, no firma, no muestra secretos. Detalle por documento en su ficha (panel "Estado técnico DTE").</p>
            </div>

            {{-- Datos --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-4">Datos principales</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach ($datos as $d)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-2xl font-bold font-mono text-gray-800">{{ $d['valor'] }}</div>
                            <div class="text-xs text-gray-500">{{ $d['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Alertas --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-4">Alertas de datos</h3>
                @php $alertasActivas = collect($alertas)->where('count', '>', 0); @endphp
                @if ($alertasActivas->isEmpty())
                    <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-md p-3">Sin alertas de datos. ✔</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($alertasActivas as $a)
                            <li class="flex items-center justify-between py-2 text-sm">
                                <span class="text-gray-700">{{ $a['label'] }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="font-mono text-gray-800">{{ $a['count'] }}</span>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$a['estado']] }}">{{ $badgeTexto[$a['estado']] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-gray-400 mt-3">Solo se reportan; no se corrige nada automáticamente.</p>
                @endif
            </div>

            {{-- Auditoría reciente --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-4">Auditoría reciente</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Usuario</th>
                                <th class="px-3 py-2">Log</th>
                                <th class="px-3 py-2">Acción</th>
                                <th class="px-3 py-2">Modelo</th>
                                <th class="px-3 py-2">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($auditoria as $a)
                                <tr>
                                    <td class="px-3 py-2">{{ $a['usuario'] }}</td>
                                    <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ $a['log'] }}</span></td>
                                    <td class="px-3 py-2 text-gray-700">{{ $a['accion'] }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $a['modelo'] }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $a['fecha'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Sin actividad registrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
