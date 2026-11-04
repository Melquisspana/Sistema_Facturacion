{{-- CCF anotados como facturados DESPUÉS de entregar (PR #230). Desde el 04/11/2026 ya no se
     anotan nuevos: el producto nunca se entrega sin CCF. Los que quedaron se muestran solo para
     leer, aparte porque no tienen dinero, y la oficina (rutas.gestionar) los puede deshacer.
     Sin registros, la sección no aparece. --}}
@if ($facturadosDespues->isNotEmpty())
    <section class="{{ $caja }} mt-6 p-5" aria-labelledby="facturados-despues-titulo">
        <h2 id="facturados-despues-titulo" class="text-lg font-bold text-gray-800 dark:text-paper-100">Facturados después de entregar (sin dinero)</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">Registros anteriores. No suman al día ni a la liquidación: el cobro sigue el camino normal del CCF.</p>

        <ul class="mt-3 divide-y divide-gray-200 dark:divide-ink-600">
            @foreach ($facturadosDespues as $fd)
                <li class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div class="min-w-0 flex-1 break-words">
                        <p class="text-xs font-medium text-gray-500 dark:text-paper-300">{{ $fd->sala?->nombre ?? 'Sala sin identificar' }}</p>
                        <p class="font-mono text-sm">
                            <a href="{{ route('rutas.salidas.ccf', [$salida, $fd]) }}" download class="inline-flex min-h-11 items-center text-indigo-600 underline dark:text-indigo-400"><span class="sr-only">Descargar el CCF </span>{{ $fd->dte?->numero_control ?? '—' }}</a>
                        </p>
                        <p class="text-sm text-gray-800 dark:text-paper-100">Entregó {{ $fd->entregadoPor?->nombre ?? '—' }} · {{ $fd->fecha_resultado?->format('d/m/Y') }}</p>
                        @if ($fd->nota)
                            <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">{{ $fd->nota }}</p>
                        @endif
                        <p class="mt-1 text-xs text-gray-500 dark:text-paper-300">Anotó {{ $fd->registradoPor?->name ?? '—' }} el {{ $fd->created_at?->format('d/m/Y H:i') }}</p>
                    </div>
                    @if ($puedeGestionar)
                        <form method="POST" action="{{ route('rutas.salidas.entregas.facturado-despues.destroy', [$salida, $fd]) }}"
                              onsubmit="return confirm('¿Deshacer este registro? El CCF vuelve a quedar sin entregar. No mueve dinero.');">
                            @csrf @method('DELETE')
                            <button class="min-h-11 rounded-lg px-3 text-sm text-red-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 dark:text-red-300" aria-label="Deshacer el CCF {{ $fd->dte?->numero_control }}">Deshacer</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
