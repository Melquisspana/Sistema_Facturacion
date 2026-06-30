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

            {{-- Backups --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-4">Backups</h3>
                <p class="text-sm text-gray-500 mb-3">Ruta esperada: <span class="font-mono">{{ $backups['ruta'] }}</span></p>

                <div class="rounded-lg border border-gray-200 p-4 mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm text-gray-500">Último backup</span>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$backups['ultimo']['estado']] }}">{{ $badgeTexto[$backups['ultimo']['estado']] }}</span>
                    </div>
                    @if ($backups['ultimo']['nombre'])
                        <div class="font-mono text-gray-800 text-sm break-all">{{ $backups['ultimo']['nombre'] }}</div>
                        <p class="text-xs text-gray-500 mt-1">Fecha: {{ $backups['ultimo']['fecha'] }} · Tamaño: {{ $backups['ultimo']['tamano'] }}</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">{{ $backups['ultimo']['detalle'] }}</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Scripts</p>
                        @foreach ($backups['scripts'] as $s)
                            <div class="flex items-center justify-between text-sm py-1">
                                <span class="font-mono text-gray-700">{{ $s['label'] }}</span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$s['estado']] }}">{{ $s['valor'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Documentación</p>
                        @foreach ($backups['docs'] as $d)
                            <div class="flex items-center justify-between text-sm py-1">
                                <span class="font-mono text-gray-700">{{ $d['label'] }}</span>
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $badge[$d['estado']] }}">{{ $d['valor'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
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
