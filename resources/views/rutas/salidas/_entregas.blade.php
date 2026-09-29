{{-- CCF de la salida, por sala. Pensado para el celular: dos botones grandes por CCF.
     La regla (qué se puede hacer y cuándo) vive en EntregasCcf; acá solo se dibuja lo
     que el estado permite.

     «¿Quién entrega?» se elige UNA vez arriba y viaja en cada formulario (campo
     .js-quien). Sin JavaScript igual funciona: el valor inicial es el preseleccionado. --}}
@php
    $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
    $quien = $preseleccion ?? $participantes->first()?->rutas_personal_id;
    $puedeMarcar = $puedeGestionar && $registrable && $participantes->isNotEmpty();
@endphp

@if ($puedeMarcar && $participantes->count() > 1)
    <div class="sticky top-0 z-10 mt-4 rounded-2xl bg-white/95 px-4 py-3 shadow-sm ring-1 ring-gray-200 backdrop-blur dark:bg-ink-800/95 dark:ring-ink-600">
        <p class="text-xs font-medium text-gray-500 dark:text-paper-400">¿Quién entrega?</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($participantes as $p)
                <label class="cursor-pointer">
                    <input type="radio" name="quien" value="{{ $p->rutas_personal_id }}" class="peer sr-only js-elige-quien" @checked($p->rutas_personal_id === $quien)>
                    <span class="inline-block rounded-full px-4 py-2 text-sm ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 dark:text-paper-200 dark:ring-ink-500">{{ $p->personal->nombre }}</span>
                </label>
            @endforeach
        </div>
    </div>
@endif

@if ($salida->estado === \App\Enums\EstadoSalidaRuta::Planificada && $resumen['total'] > 0)
    <p class="mt-4 text-sm text-gray-500 dark:text-paper-400">Tocá «Salir ahora» para empezar a marcar entregas.</p>
@endif

@forelse ($porSala as $entregas)
    @php
        $sala = $entregas->first()->sala;
        $pendientesSala = $entregas->filter(fn ($e) => $e->estaPendiente() && ! ($resoluciones[$e->dte_id] ?? null)?->estaVinculado())->count();
    @endphp
    <div class="{{ $caja }} mt-4 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-ink-700">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-gray-800 dark:text-paper-100">{{ $sala?->nombre ?? 'Sala sin identificar' }}</p>
                <p class="text-xs text-gray-400 dark:text-paper-500">{{ $entregas->count() }} CCF{{ $pendientesSala ? ' · '.$pendientesSala.' por marcar' : '' }}</p>
            </div>
            @if ($puedeMarcar && $pendientesSala > 1 && $sala)
                <form method="POST" action="{{ route('rutas.salidas.entregas.sala', [$salida, $sala]) }}">
                    @csrf
                    <input type="hidden" name="entregado_por_id" value="{{ $quien }}" class="js-quien">
                    <button class="rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">Todo entregado</button>
                </form>
            @endif
        </div>

        <div class="divide-y divide-gray-100 dark:divide-ink-700">
            @foreach ($entregas as $entrega)
                @php
                    $resolucion = $resoluciones[$entrega->dte_id] ?? null;
                    $albaran = $resolucion?->estaVinculado() ? $resolucion->albaran : null;
                @endphp
                <div class="px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-xs text-gray-600 dark:text-paper-300">{{ $entrega->dte?->numero_control ?? '—' }}</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-paper-100">${{ number_format((float) $entrega->dte?->total_pagar, 2) }}</p>
                        </div>

                        {{-- Resultado ya registrado --}}
                        <div class="shrink-0 text-right">
                            @if ($entrega->resultado === \App\Enums\ResultadoEntrega::Entregado)
                                <p class="text-sm font-medium text-green-700 dark:text-green-400">✓ Entregado</p>
                                <p class="text-xs text-gray-500 dark:text-paper-400">
                                    {{ $entrega->entregadoPor?->nombre }} · {{ $entrega->fecha_resultado?->translatedFormat('d M') }}{{ $entrega->trae_nota_averia ? ' · con nota de avería' : '' }}
                                </p>
                            @elseif ($entrega->resultado === \App\Enums\ResultadoEntrega::NoEntregado)
                                <p class="text-sm font-medium text-red-700 dark:text-red-400">✗ No entregado</p>
                                <p class="text-xs text-gray-500 dark:text-paper-400">{{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ' · '.$entrega->nota : '' }}</p>
                            @elseif ($albaran)
                                <p class="text-sm font-medium text-green-700 dark:text-green-400">✓ Entregado</p>
                                <p class="text-xs text-gray-500 dark:text-paper-400">Llegó el albarán {{ $albaran->numero_albaran }}</p>
                            @endif
                            @if ($puedeGestionar && ! $entrega->estaPendiente() && $salida->estado !== \App\Enums\EstadoSalidaRuta::Cancelada)
                                <form method="POST" action="{{ route('rutas.salidas.entregas.deshacer', [$salida, $entrega]) }}" class="mt-1">
                                    @csrf @method('PATCH')
                                    <button class="text-xs text-gray-400 hover:underline dark:text-paper-500">Deshacer</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    {{-- Por marcar: dos botones grandes --}}
                    @if ($puedeMarcar && $entrega->estaPendiente() && ! $albaran)
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <form method="POST" action="{{ route('rutas.salidas.entregas.update', [$salida, $entrega]) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="resultado" value="entregado">
                                <input type="hidden" name="entregado_por_id" value="{{ $quien }}" class="js-quien">
                                <button class="w-full rounded-xl bg-green-600 px-3 py-3 text-sm font-semibold text-white hover:bg-green-700">Entregado</button>
                                <label class="mt-1.5 flex items-center gap-2 text-xs text-gray-600 dark:text-paper-300">
                                    <input type="checkbox" name="trae_nota_averia" value="1" class="h-4 w-4 rounded border-gray-300 text-amber-600 dark:border-ink-600 dark:bg-ink-800">
                                    Trae nota de avería
                                </label>
                            </form>

                            <details class="group">
                                <summary class="w-full cursor-pointer list-none rounded-xl bg-white px-3 py-3 text-center text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50 dark:bg-ink-800 dark:text-red-300 dark:ring-red-500/40">
                                    No entregado
                                </summary>
                                <form method="POST" action="{{ route('rutas.salidas.entregas.update', [$salida, $entrega]) }}" class="mt-2 space-y-1.5">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="resultado" value="no_entregado">
                                    @foreach ($motivos as $valor => $texto)
                                        @continue($valor === 'otro')
                                        <button name="motivo_no_entrega" value="{{ $valor }}"
                                                class="block w-full rounded-lg bg-gray-50 px-3 py-2 text-left text-sm text-gray-700 hover:bg-red-50 hover:text-red-700 dark:bg-ink-700 dark:text-paper-200">
                                            {{ $texto }}
                                        </button>
                                    @endforeach
                                    <div class="flex gap-1.5">
                                        <input name="nota" maxlength="300" placeholder="Otro motivo…"
                                               class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                        <button name="motivo_no_entrega" value="otro" class="rounded-lg bg-gray-800 px-3 text-sm text-white dark:bg-paper-100 dark:text-ink-900">OK</button>
                                    </div>
                                </form>
                            </details>
                        </div>
                    @endif

                    @if ($puedeGestionar && $entrega->estaPendiente() && $abierta)
                        <form method="POST" action="{{ route('rutas.salidas.entregas.destroy', [$salida, $entrega]) }}" class="mt-2 text-right">
                            @csrf @method('DELETE')
                            <button class="text-xs text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500">Quitar de la salida</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="{{ $caja }} mt-4 px-5 py-10 text-center">
        <p class="text-base font-medium text-gray-800 dark:text-paper-100">Esta salida no lleva CCF</p>
        <p class="mt-1 text-sm text-gray-500 dark:text-paper-400">No había CCF pendientes para esta ruta cuando se creó.</p>
    </div>
@endforelse

{{-- ¿Falta algo? Lo poco frecuente va plegado. --}}
@if ($puedeGestionar && $abierta)
    <details class="{{ $caja }} mt-4 px-5 py-4">
        <summary class="cursor-pointer text-sm font-medium text-gray-600 dark:text-paper-300">¿Falta un CCF?</summary>
        <div class="mt-3 space-y-3">
            <form method="POST" action="{{ route('rutas.salidas.entregas.cargar', $salida) }}">
                @csrf
                <button class="w-full rounded-lg bg-gray-100 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200">Buscar CCF nuevos de la ruta</button>
            </form>
            <form method="POST" action="{{ route('rutas.salidas.entregas.store', $salida) }}" class="flex gap-2">
                @csrf
                <input name="numero_control" value="{{ old('numero_control') }}" placeholder="Número de control del CCF" aria-label="Número de control del CCF"
                       class="min-w-0 flex-1 rounded-lg border-gray-300 font-mono text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                <button class="rounded-lg bg-gray-800 px-4 text-sm font-medium text-white dark:bg-paper-100 dark:text-ink-900">Agregar</button>
            </form>
        </div>
    </details>
@endif

@if ($puedeMarcar && $participantes->count() > 1)
    <script>
        document.querySelectorAll('.js-elige-quien').forEach((radio) => radio.addEventListener('change', () => {
            document.querySelectorAll('.js-quien').forEach((campo) => { campo.value = radio.value; });
        }));
    </script>
@endif
