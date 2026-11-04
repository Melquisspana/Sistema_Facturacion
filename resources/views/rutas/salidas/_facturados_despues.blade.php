{{-- CCF facturados DESPUÉS de entregar el producto (docs/PLAN_TABLERO_RUTAS.md, PR 5): el
     producto se entregó en esta salida y la orden de compra llegó después. Van aparte porque
     no tienen dinero: no suman al día ni a la liquidación. Anotarlos y deshacerlos es solo de
     la oficina (rutas.gestionar). La regla vive en EntregasCcf::anotarFacturadoDespues. --}}
@php
    $puedeAnotar = $puedeGestionar && in_array($salida->estado, [\App\Enums\EstadoSalidaRuta::EnCurso, \App\Enums\EstadoSalidaRuta::Finalizada], true);
    $erroresFd = $errors->getBag('facturadoDespues');
    $conErrores = $erroresFd->any();
    [$diaDesde, $diaHasta] = $diasDeLaSalida;
    $quienesFueron = $salida->participantes->filter(fn ($p) => $p->personal)->sortBy(fn ($p) => $p->personal->nombre);
    $campo = 'mt-1 min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100';
@endphp

@if ($puedeAnotar || $facturadosDespues->isNotEmpty())
    <section class="{{ $caja }} mt-6 p-5" aria-labelledby="facturados-despues-titulo">
        <h2 id="facturados-despues-titulo" class="text-lg font-bold text-gray-800 dark:text-paper-100">Facturados después de entregar (sin dinero)</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">El producto se entregó en esta salida y el CCF salió después. No suman al día ni a la liquidación: el cobro sigue el camino normal del CCF.</p>

        <ul class="mt-3 divide-y divide-gray-200 dark:divide-ink-600">
            @forelse ($facturadosDespues as $fd)
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
            @empty
                <li class="py-3 text-sm text-gray-600 dark:text-paper-300">Todavía no hay CCF facturados después de entregar en esta salida.</li>
            @endforelse
        </ul>

        @if ($puedeAnotar)
            <details class="mt-3" @if ($conErrores) open @endif>
                <summary class="inline-flex min-h-11 cursor-pointer list-none items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Entregado antes de facturar</summary>
                <form method="POST" action="{{ route('rutas.salidas.entregas.facturado-despues', $salida) }}" class="mt-4 space-y-4">
                    @csrf
                    @if ($conErrores)
                        <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                            <p class="font-medium">Revisá estos datos. No se guardó nada.</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($erroresFd->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <div class="min-w-0 flex-1">
                            <label for="fd-numero" class="block text-sm font-semibold text-gray-800 dark:text-paper-100">Número del CCF</label>
                            <input id="fd-numero" name="numero_control" required maxlength="60" value="{{ $conErrores ? old('numero_control') : '' }}" placeholder="Número de control" class="{{ $campo }} font-mono">
                        </div>
                        <div class="w-28">
                            <label for="fd-ejercicio" class="block text-sm font-semibold text-gray-800 dark:text-paper-100">Año <span class="font-normal text-gray-600 dark:text-paper-300">(opcional)</span></label>
                            <input id="fd-ejercicio" type="number" name="ejercicio" min="2026" max="2100" value="{{ $conErrores ? old('ejercicio') : '' }}" aria-describedby="fd-ejercicio-ayuda" class="{{ $campo }} font-mono">
                        </div>
                    </div>
                    <p id="fd-ejercicio-ayuda" class="text-xs text-gray-500 dark:text-paper-300">El año, solo si el número existe en más de un año.</p>
                    @if ($erroresFd->has('numero_control'))
                        <p class="text-sm text-red-700 dark:text-red-300">{{ $erroresFd->first('numero_control') }}</p>
                    @endif

                    <div>
                        <label for="fd-quien" class="block text-sm font-semibold text-gray-800 dark:text-paper-100">¿Quién lo entregó?</label>
                        <select id="fd-quien" name="entregado_por_id" required class="{{ $campo }}">
                            <option value="">Elegí quién</option>
                            @foreach ($quienesFueron as $p)
                                <option value="{{ $p->rutas_personal_id }}" @selected($conErrores ? (string) old('entregado_por_id') === (string) $p->rutas_personal_id : $quienesFueron->count() === 1)>{{ $p->personal->nombre }}</option>
                            @endforeach
                        </select>
                        @if ($erroresFd->has('entregado_por_id'))
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $erroresFd->first('entregado_por_id') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="fd-fecha" class="block text-sm font-semibold text-gray-800 dark:text-paper-100">¿Qué día?</label>
                        <input id="fd-fecha" type="date" name="fecha_entrega" required min="{{ $diaDesde }}" max="{{ $diaHasta }}" value="{{ $conErrores ? old('fecha_entrega') : ($diaDesde === $diaHasta ? $diaDesde : '') }}" aria-describedby="fd-fecha-ayuda" class="{{ $campo }}">
                        <p id="fd-fecha-ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Entre el {{ \Illuminate\Support\Carbon::parse($diaDesde)->format('d/m/Y') }} y el {{ \Illuminate\Support\Carbon::parse($diaHasta)->format('d/m/Y') }}.</p>
                        @if ($erroresFd->has('fecha_entrega'))
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $erroresFd->first('fecha_entrega') }}</p>
                        @endif
                    </div>

                    <div>
                        <label for="fd-nota" class="block text-sm font-semibold text-gray-800 dark:text-paper-100">Nota <span class="font-normal text-gray-600 dark:text-paper-300">(opcional)</span></label>
                        <input id="fd-nota" name="nota" maxlength="300" value="{{ $conErrores ? old('nota') : '' }}" aria-describedby="fd-nota-ayuda" class="{{ $campo }}">
                        <p id="fd-nota-ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Si la sala pagó en el momento, anotalo acá: ese dinero no se suma a la salida.</p>
                        @if ($erroresFd->has('nota'))
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $erroresFd->first('nota') }}</p>
                        @endif
                    </div>

                    <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 dark:border-ink-700 sm:flex-row sm:justify-end">
                        <a href="{{ route('rutas.salidas.show', $salida) }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 dark:hover:bg-ink-700">Cancelar</a>
                        <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Guardar</button>
                    </div>
                </form>
            </details>
        @endif
    </section>
@endif
