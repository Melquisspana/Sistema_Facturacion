@props(['ajuste'])
@php
    $estado = $ajuste->estadoEfectivo();
    // Los mismos dos estilos de botón del resto de Cobros: primario como «Cargar pagos»,
    // secundario como «Historial de PPQ».
    $primario = 'inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700';
    $secundario = 'inline-flex items-center rounded-md border border-gray-300 dark:border-ink-600 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-paper-100 hover:bg-gray-50 dark:hover:bg-ink-700';
@endphp
<div class="flex flex-wrap items-center gap-2 py-2 text-sm text-gray-800 dark:text-paper-100">
    <span class="font-mono">{{ $ajuste->referencia }}</span>
    <span class="font-mono text-rose-700 dark:text-rose-300">{{ (float) $ajuste->monto < 0 ? '-' : '' }}${{ number_format(abs((float) $ajuste->monto), 2) }}</span>
    <span class="inline-flex rounded-full px-2 py-0.5 text-xs {{ $ajuste->clase() }}">{{ $ajuste->label() }}</span>
    @if ($ajuste->notaCredito)
        @can('view', $ajuste->notaCredito)
            <a href="{{ route(auth()->user()->can('update', $ajuste->notaCredito) ? 'facturacion.edit' : 'facturacion.show', $ajuste->notaCredito) }}" class="text-indigo-700 dark:text-indigo-300 hover:underline">
                Ver NC {{ $ajuste->notaCredito->numero_control ?: '#'.$ajuste->nc_dte_id }}
            </a>
        @endcan
        @can('ppq.gestionar')
            <form method="POST" action="{{ route('cobros.ajustes.desvincular-nc', $ajuste) }}">
                @csrf
                <button class="{{ $secundario }}">Desvincular NC</button>
            </form>
        @endcan
    @endif
    @if ($estado === 'pendiente_nc')
        @can('create', \App\Models\Dte::class)
            <a href="{{ route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id]) }}" class="{{ $primario }}">Crear nota de crédito</a>
        @endcan
        @can('ppq.gestionar')
            <a href="{{ route('cobros.ajustes.notas-credito', $ajuste) }}" class="{{ $secundario }}">Vincular NC existente</a>
        @endcan
    @endif
    @if ($ajuste->motivo)
        <p class="w-full text-xs text-gray-600 dark:text-paper-300">{{ $ajuste->motivo }}</p>
    @endif
</div>
