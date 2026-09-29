@props(['resumen'])
@php
    use App\Services\Gastos\SaldosGastos;

    // DOS ejes, DOS insignias. «Parcialmente pagado» habla de cuánto se cubrió;
    // «Vencida» habla de la fecha. Fundirlos en un estado único obliga a elegir cuál
    // se pierde, y en la práctica se perdía el parcial: un gasto con abono se veía
    // igual que uno sin pagar nada.
    $liquidacion = [
        'sin_pagos' => 'bg-gray-100 text-gray-700',
        'parcial' => 'bg-amber-100 text-amber-800',
        'pagada' => 'bg-emerald-100 text-emerald-800',
        'saldada_por_ajuste' => 'bg-blue-100 text-blue-700',
        'saldada_mixta' => 'bg-emerald-100 text-emerald-800',
        'credito_a_favor' => 'bg-violet-100 text-violet-700',
        'por_determinar' => 'bg-gray-100 text-gray-700',
    ];

    $vencimiento = [
        'sin_fecha' => 'bg-gray-100 text-gray-600',
        'proxima' => 'bg-gray-100 text-gray-700',
        'hoy' => 'bg-amber-100 text-amber-800',
        'vencida' => 'bg-red-100 text-red-700',
        'saldada' => 'bg-gray-100 text-gray-600',
    ];

    $ejeL = $resumen['liquidacion'];
    $ejeV = $resumen['vencimiento'];
@endphp
<span class="flex flex-wrap items-center gap-1.5">
    <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium {{ $liquidacion[$ejeL] ?? 'bg-gray-100 text-gray-700' }}">
        {{ SaldosGastos::LIQUIDACION[$ejeL] ?? $ejeL }}
    </span>
    {{-- El eje de vencimiento se calla cuando no aporta: sin saldo no hay reclamo. --}}
    @if ($ejeV !== 'saldada')
        <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium {{ $vencimiento[$ejeV] ?? 'bg-gray-100 text-gray-700' }}">
            {{ SaldosGastos::VENCIMIENTO[$ejeV] ?? $ejeV }}
        </span>
    @endif
</span>
