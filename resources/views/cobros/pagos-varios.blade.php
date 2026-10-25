<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">Puesta al día de Cobros</h2>
    </x-slot>
    @php
        $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
        $etiquetas = ['pagado' => 'Pagado', 'diferencia' => 'Pagado con diferencia', 'no_pagado' => 'No pagado', 'en_revision' => 'En revisión', 'descontada' => 'Descontada', 'no_descontada' => 'No descontada', 'pronto_pago' => 'Pronto pago'];
    @endphp
    <div class="py-8">
        <div class="max-w-full xl:max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6 text-sm text-gray-700 dark:text-paper-100">
            @if ($soloRevisar)
                <p class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700" role="status">Solo revisión: no se guardó nada.</p>
            @endif
            <a href="{{ route('cobros.index', ['cliente_id' => $cliente->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Volver a Cobros</a>
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="text-left font-medium">Archivos procesados</caption>
                    <thead><tr>
                        @foreach (['Archivo', 'Estado', 'Aplicados', 'Sin cambio', 'En revisión', 'No identificados'] as $titulo)
                            <th scope="col" class="p-2 text-left font-medium">{{ $titulo }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($archivos as $archivo)
                            <tr>
                                <td class="p-2">{{ $archivo['nombre'] }}</td>
                                <td class="p-2">{{ $archivo['estado'] === 'aplicado' ? ($soloRevisar ? 'Revisado' : 'Aplicado') : 'Error' }}</td>
                                @if ($archivo['estado'] === 'error')
                                    <td colspan="4" class="p-2">{{ $archivo['mensaje'] }}</td>
                                @else
                                    @foreach (['aplicados', 'sin_cambio', 'en_revision', 'no_identificados'] as $grupo)
                                        <td class="p-2">{{ count($archivo['informe'][$grupo]) }}</td>
                                    @endforeach
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium">Estado de cobro de {{ $cliente->nombre }}</h3>
                <p>{{ $reporte['inicio'] ? 'Desde '.$reporte['inicio']->format('d/m/Y') : 'Sin fecha de inicio configurada: se informan todos los documentos.' }}</p>
                @foreach (['ccf' => 'CCF', 'nc' => 'NC'] as $tipo => $titulo)
                    <h4 class="font-medium">{{ $titulo }}</h4>
                    <ul>
                        @foreach ($reporte['totales'][$tipo] as $estado => $cantidad)
                            <li>{{ $etiquetas[$estado] }}: {{ $cantidad }}</li>
                        @endforeach
                    </ul>
                @endforeach
                @if (! $soloRevisar)
                    <a href="{{ route('cobros.estado-cobro', $cliente) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Descargar estado de cobro en Excel</a>
                @endif
            </div>
            @foreach (['no_pagado' => 'No pagados', 'diferencia' => 'Pagados con diferencia', 'en_revision' => 'En revisión', 'no_descontada' => 'NC no descontadas'] as $estado => $titulo)
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 overflow-x-auto">
                    <h3 class="font-medium">{{ $titulo }}</h3>
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">{{ $titulo }}</caption>
                        <thead><tr>
                            @foreach (['Número', 'Fecha', 'A cobrar / monto NC', 'Cobrado', 'Diferencia', 'TXT', 'PPQ'] as $columna)
                                <th scope="col" class="p-2 text-left font-medium">{{ $columna }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @forelse (collect($reporte[$estado === 'no_descontada' ? 'nc' : 'ccf'])->where('estado', $estado) as $fila)
                                @php($doc = $fila[$estado === 'no_descontada' ? 'nc' : 'documento'])
                                <tr>
                                    <td class="p-2 font-mono">{{ $doc->numero_control }}</td>
                                    <td class="p-2">{{ $doc->fecha_emision?->format('d/m/Y') }}</td>
                                    <td class="p-2">{{ $money($fila['a_cobrar'] ?? $doc->total_pagar) }}</td>
                                    <td class="p-2">{{ isset($fila['cobrado']) ? $money($fila['cobrado']) : '—' }}</td>
                                    <td class="p-2">{{ isset($fila['diferencia']) ? $money($fila['diferencia']) : '—' }}</td>
                                    <td class="p-2">{{ implode(' · ', $fila['txt']) ?: '—' }}</td>
                                    <td class="p-2">{{ implode(' · ', $fila['ppq']) ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-2">Ninguno.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
            @if (! $soloRevisar)
                @foreach ($archivos as $archivo)
                    @if ($archivo['estado'] === 'aplicado' && collect($archivo['pendientes'])->flatten(1)->isNotEmpty())
                        <h3 class="font-medium">{{ $archivo['nombre'] }}</h3>
                        @include('cobros.partials.pendientes-txt', ['informe' => $archivo['informe'], 'pendientes' => $archivo['pendientes']])
                    @endif
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
