@props(['serie'])
@php
    $montos = array_column($serie, 'monto');
    $max = max($montos);
    $min = min(0, ...$montos);
    $rango = max(1, $max - $min);
    $puntos = [];
    foreach ($serie as $i => $mes) {
        $puntos[] = (4 + $i * 92 / 11).','.round(44 - ($mes['monto'] - $min) / $rango * 40, 2);
    }
    $primero = $serie[0];
    $ultimo = $serie[11];
    $mayor = $serie[array_search($max, $montos)];
    $yUltimo = round(44 - ($ultimo['monto'] - $min) / $rango * 40, 2);
@endphp
<div class="pt-2 text-indigo-600 dark:text-indigo-400">
    <svg width="100%" height="48" viewBox="0 0 100 48" preserveAspectRatio="none" role="img" aria-label="Tendencia de 12 meses. Máximo: {{ $mayor['etiqueta'] }}, ${{ number_format($max, 2) }}. Último mes: {{ $ultimo['etiqueta'] }}, ${{ number_format($ultimo['monto'], 2) }}.">
        <polyline points="{{ implode(' ', $puntos) }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
        <circle cx="96" cy="{{ $yUltimo }}" r="1" fill="currentColor" />
    </svg>
    <div class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-gray-500 dark:text-paper-300"><span>{{ $primero['etiqueta'] }}</span><span>Máximo {{ $mayor['etiqueta'] }} · ${{ number_format($max, 2) }}</span><span>{{ $ultimo['etiqueta'] }}</span></div>
</div>
