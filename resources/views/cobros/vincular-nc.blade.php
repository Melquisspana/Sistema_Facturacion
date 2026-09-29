<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-paper-100">Vincular NC existente</h2></x-slot>
    <div class="max-w-4xl mx-auto p-6 space-y-4 text-gray-800 dark:text-paper-100">
        <p>Ajuste {{ $ajuste->referencia }} · Importe del TXT: ${{ number_format(abs((float) $ajuste->monto), 2) }}</p>
        @foreach ($errors->all() as $error)
            <p class="text-red-700 dark:text-red-300">{{ $error }}</p>
        @endforeach
        <div class="divide-y divide-gray-200 dark:divide-ink-600 rounded-lg bg-white dark:bg-ink-800 p-4">
            @forelse ($notas as $nc)
                <form method="POST" action="{{ route('cobros.ajustes.vincular-nc', $ajuste) }}" class="flex flex-wrap items-center gap-3 py-3">
                    @csrf
                    <input type="hidden" name="nc_dte_id" value="{{ $nc->id }}">
                    <span>{{ $nc->numero_control ?: 'NC #'.$nc->id }} · {{ $nc->clienteSucursal?->nombre ?? 'Sin sala' }} · {{ $nc->estado->label() }}</span>
                    <span>${{ number_format((float) $nc->total_pagar, 2) }}</span>
                    @php($coincide = abs((float) $nc->total_pagar - abs((float) $ajuste->monto)) < 0.005)
                    @if ($coincide)
                        <span class="text-xs text-green-700 dark:text-green-300">coincide con el TXT</span>
                    @endif
                    {{-- Mismos estilos que el resto de Cobros; la que coincide va como primaria. --}}
                    <button class="{{ $coincide
                        ? 'inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700'
                        : 'inline-flex items-center rounded-md border border-gray-300 dark:border-ink-600 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-paper-100 hover:bg-gray-50 dark:hover:bg-ink-700' }}">Vincular esta NC</button>
                </form>
            @empty
                <p>No hay notas de crédito de pronto pago disponibles.</p>
            @endforelse
        </div>
        {{ $notas->links() }}
        <a href="{{ route('cobros.index', ['cliente_id' => $ajuste->cliente_id]) }}" class="text-indigo-700 dark:text-indigo-300 underline">Volver a Cobros</a>
    </div>
</x-app-layout>
