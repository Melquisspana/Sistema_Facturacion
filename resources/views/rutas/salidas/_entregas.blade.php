{{-- CCF de la salida: una tarjeta por CCF, ordenadas por sala. Pensado para el celular.
     La regla (qué se puede hacer y cuándo) vive en EntregasCcf; acá solo se dibuja lo
     que el estado permite.

     Cada tarjeta pregunta quién lo entregó, entre los que van en la salida (en «Mi
     salida» viene elegido el vendedor). Lo que trae albarán de Calleja ya consta
     entregado: la tarjeta solo pide quién. Arriba, «Faltan / Entregados / Todos» filtra
     la lista (?ver=), sin JavaScript. --}}
@php
    $modoVendedor = $modoVendedor ?? false;
    $fechaHoy = \App\Support\HoraNegocio::fechaHoy();
    $cierreHoy = $salida->cierreDelDia($fechaHoy);
    $diaCerrado = $cierreHoy?->estaCerrado() ?? false;
    $registrable = $registrable && ! $diaCerrado;
    $prefijoCierre = $modoVendedor ? 'rutas.mi-salida.' : 'rutas.salidas.';
    $saldosAnteriores = app(\App\Services\Rutas\CierreDia::class)->saldosAnteriores($salida, $porSala->keys()->all());
    $prefijoEntregas = $modoVendedor ? 'rutas.mi-salida.' : 'rutas.salidas.entregas.';
    $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
    $puedeMarcar = ($modoVendedor || $puedeGestionar) && $registrable && $participantes->isNotEmpty();
    // Quién viene elegido en cada tarjeta: en «Mi salida», el vendedor; en la oficina, el
    // responsable o, si va una sola persona, ella.
    $quien = $modoVendedor ? $persona?->id : ($preseleccion ?? $participantes->first()?->rutas_personal_id);
    $pastilla = 'inline-flex min-h-11 items-center rounded-full px-4 text-sm ring-1 ring-gray-300 dark:text-paper-100 dark:ring-ink-500';

    // Qué se ve: lo que falta marcar, lo entregado (también por albarán) o todo.
    $ver = in_array(request('ver'), ['faltan', 'entregados'], true) ? request('ver') : 'todos';
    $porAlbaran = fn ($e) => $e->estaPendiente() && ($resoluciones[$e->dte_id] ?? null)?->estaVinculado();
    $seVe = fn ($e) => match ($ver) {
        'faltan' => $e->estaPendiente() && ! $porAlbaran($e),
        'entregados' => $e->resultado === \App\Enums\ResultadoEntrega::Entregado || $porAlbaran($e),
        default => true,
    };
@endphp

@if ($resumen['total'] > 0)
    <nav aria-label="Qué CCF mostrar" class="mt-4 flex gap-2 overflow-x-auto">
        @foreach (['faltan' => 'Faltan '.$resumen['sin_registrar'], 'entregados' => 'Entregados '.$resumen['entregados'], 'todos' => 'Todos '.$resumen['total']] as $clave => $texto)
            <a href="{{ request()->fullUrlWithQuery(['ver' => $clave === 'todos' ? null : $clave]) }}" @if ($ver === $clave) aria-current="page" @endif
               class="inline-flex min-h-11 shrink-0 items-center rounded-full px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $ver === $clave ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500' }}">{{ $texto }}</a>
        @endforeach
    </nav>
@endif

@if ($salida->estado === \App\Enums\EstadoSalidaRuta::Planificada && $resumen['total'] > 0)
    <p class="mt-4 text-sm text-gray-500 dark:text-paper-300">Tocá «Salir ahora» para empezar a marcar entregas.</p>
@endif

@if ($ver !== 'todos' && ! $porSala->collapse()->contains($seVe))
    <div class="{{ $caja }} mt-4 px-5 py-8 text-center">
        <p class="text-base font-medium text-gray-800 dark:text-paper-100">{{ $ver === 'faltan' ? 'No falta marcar ningún CCF.' : 'Todavía no hay CCF entregados.' }}</p>
        <a href="{{ request()->fullUrlWithQuery(['ver' => null]) }}" class="mt-2 inline-flex min-h-11 items-center text-sm text-indigo-600 underline dark:text-indigo-300">Ver todos</a>
    </div>
@endif

@forelse ($porSala as $entregas)
    @php
        $sala = $entregas->first()->sala;
        $visibles = $entregas->filter($seVe);
    @endphp
    @continue($visibles->isEmpty())
    @foreach ($visibles as $entrega)
        @php
            $resolucion = $resoluciones[$entrega->dte_id] ?? null;
            $albaran = $resolucion?->estaVinculado() ? $resolucion->albaran : null;
            // Tocar la sala o el número abre el PDF del CCF (se ve y se guarda en el celular).
            $verCcf = $entrega->dte ? route($modoVendedor ? 'rutas.mi-salida.ccf' : 'rutas.salidas.ccf', [$salida, $entrega]) : null;
        @endphp
        <div class="{{ $caja }} mt-3 px-4 py-3">
            @if ($verCcf)
                {{-- Repite el enlace del número para el dedo; el lector de pantalla usa el del número. --}}
                <a href="{{ $verCcf }}" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true" class="block truncate text-xs font-medium text-gray-500 hover:underline dark:text-paper-300">{{ $sala?->nombre ?? 'Sala sin identificar' }}</a>
            @else
                <p class="truncate text-xs font-medium text-gray-500 dark:text-paper-300">{{ $sala?->nombre ?? 'Sala sin identificar' }}</p>
            @endif
            @if ($loop->first)
                @foreach ($saldosAnteriores->get($sala?->id, collect()) as $saldo)
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">Saldo de visitas anteriores: ${{ number_format((float) $saldo->monto_esperado - (float) $saldo->monto_cobrado, 2) }} (…{{ \App\Support\OrdenCompra::ultimosDigitos($saldo->dte->numero_control) }})</p>
                @endforeach
            @endif
            <div class="mt-1 flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                    {{-- En el celular, el final del correlativo (lo que se busca en el papel); el número completo queda en el title y para el lector de pantalla. --}}
                    <p class="font-mono text-sm text-gray-600 dark:text-paper-300" title="{{ $entrega->dte?->numero_control }}">
                        @if ($verCcf)
                            <a href="{{ $verCcf }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-indigo-600 underline dark:text-indigo-400">
                                <span class="sr-only">Ver el CCF </span>
                        @endif
                        <span class="sm:hidden" aria-hidden="true">{{ $entrega->dte?->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($entrega->dte->numero_control) : '—' }}</span>
                        <span class="sr-only sm:not-sr-only sm:break-all">{{ $entrega->dte?->numero_control ?? '—' }}</span>
                        @if ($verCcf)
                            </a>
                        @endif
                    </p>
                    @if (isset($noVigentes[$entrega->dte_id]))
                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $noVigentes[$entrega->dte_id] }} · no entregar</span>
                    @endif
                    <p class="text-sm font-medium text-gray-800 dark:text-paper-100">${{ number_format((float) $entrega->dte?->total_pagar, 2) }}</p>
                </div>

                {{-- Resultado ya registrado --}}
                <div class="min-w-0 max-w-full break-words text-right">
                    @if ($entrega->resultado === \App\Enums\ResultadoEntrega::Entregado)
                        @if ($entrega->monto_esperado !== null && $entrega->monto_cobrado < $entrega->monto_esperado)
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Saldo pendiente ${{ number_format((float) $entrega->monto_esperado - (float) $entrega->monto_cobrado, 2) }}</p>
                        @endif
                        <p class="text-sm font-medium text-green-700 dark:text-green-400">✓ Entregado</p>
                        <p class="text-xs text-gray-500 dark:text-paper-300">
                            {{ $entrega->entregadoPor?->nombre }} · {{ $entrega->fecha_resultado?->translatedFormat($modoVendedor ? 'H:i' : 'd M') }}{{ $entrega->trae_nota_averia ? ' · con nota de avería' : '' }}
                        </p>
                    @elseif ($entrega->resultado === \App\Enums\ResultadoEntrega::NoEntregado)
                        <p class="text-sm font-medium text-red-700 dark:text-red-400">✗ No entregado</p>
                        <p class="text-xs text-gray-500 dark:text-paper-300">{{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ' · '.$entrega->nota : '' }}</p>
                    @elseif ($albaran)
                        <p class="text-sm font-medium text-green-700 dark:text-green-400">✓ Entregado</p>
                        <p class="text-xs text-gray-500 dark:text-paper-300">Llegó el albarán {{ $albaran->numero_albaran }}{{ $puedeMarcar ? ' · falta quién lo entregó' : '' }}</p>
                    @endif
                    @if (($modoVendedor ? auth()->user()->can('deshacer', [$salida, $entrega]) : ($puedeGestionar && (! $entrega->fecha_resultado || ! $salida->diaCerrado($entrega->fecha_resultado->toDateString())))) && ! $entrega->estaPendiente() && $salida->estado !== \App\Enums\EstadoSalidaRuta::Cancelada)
                        <form method="POST" action="{{ route($prefijoEntregas.'deshacer', [$salida, $entrega]) }}" class="mt-1">
                            @csrf @method('PATCH')
                            <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 text-xs text-gray-400 hover:underline dark:text-paper-500">Deshacer</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Con albarán ya consta entregado: solo falta quién. Tocar el nombre lo guarda,
                 con la forma de pago habitual del cliente. --}}
            @if ($puedeMarcar && $albaran && $entrega->estaPendiente())
                <form method="POST" action="{{ route($prefijoEntregas.'update', [$salida, $entrega]) }}" class="mt-3">
                    @csrf @method('PATCH')
                    <input type="hidden" name="resultado" value="entregado">
                    <p class="text-xs font-medium text-gray-500 dark:text-paper-300">¿Quién lo entregó?</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ($participantes as $p)
                            <button name="entregado_por_id" value="{{ $p->rutas_personal_id }}" class="{{ $pastilla }} hover:bg-gray-100 dark:hover:bg-ink-700">{{ $p->personal->nombre }}</button>
                        @endforeach
                    </div>
                </form>
            @endif

            {{-- Por marcar: quién lo entregó y «Entregado» o «No entregado». Las pastillas
                 son del formulario de «Entregado» (atributo form). --}}
            @if ($puedeMarcar && $entrega->estaPendiente() && ! $albaran && ! isset($noVigentes[$entrega->dte_id]))
                @if ($participantes->count() > 1)
                    <fieldset class="mt-3">
                        <legend class="text-xs font-medium text-gray-500 dark:text-paper-300">¿Quién lo entregó?</legend>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($participantes as $p)
                                <label class="cursor-pointer">
                                    <input type="radio" form="entregar-{{ $entrega->id }}" name="entregado_por_id" value="{{ $p->rutas_personal_id }}" class="peer sr-only" @checked($p->rutas_personal_id === $quien)>
                                    <span class="{{ $pastilla }} peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-indigo-600">{{ $p->personal->nombre }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <form id="entregar-{{ $entrega->id }}" method="POST" action="{{ route($prefijoEntregas.'update', [$salida, $entrega]) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="resultado" value="entregado">
                        @if ($participantes->count() === 1)
                            <input type="hidden" name="entregado_por_id" value="{{ $participantes->first()->rutas_personal_id }}">
                        @endif
                        <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 w-full rounded-xl bg-green-600 px-3 py-3 text-sm font-semibold text-white hover:bg-green-700">Entregado</button>
                        <label class="mt-1.5 flex items-center gap-2 text-xs text-gray-600 dark:text-paper-300">
                            <input type="checkbox" name="trae_nota_averia" value="1" class="h-4 w-4 rounded border-gray-300 text-amber-600 dark:border-ink-600 dark:bg-ink-800">
                            Trae nota de avería
                        </label>
                        {{-- Cómo pagó la sala esta vez. Vale la habitual del cliente y no se pregunta:
                             solo si esta vez fue distinto se abre y se cambia. No toca su ficha. --}}
                        @php($habitual = $entrega->dte?->cliente?->cobro_en_entrega)
                        <details class="mt-1.5 text-xs text-gray-600 dark:text-paper-300">
                            <summary class="min-h-11 cursor-pointer py-2">Pago: {{ $habitual?->label() ?? 'Sin cobro (quedan)' }} · <span class="underline">cambiar</span></summary>
                            <label for="forma-{{ $entrega->id }}" class="sr-only">Forma de pago esta vez</label>
                            <select id="forma-{{ $entrega->id }}" name="forma_cobro" class="min-h-11 w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                <option value="{{ \App\Services\Rutas\EntregasCcf::SIN_COBRO }}" @selected($habitual === null)>Sin cobro (quedan)</option>
                                @foreach (\App\Enums\FormaCobroEntrega::opciones() as $valor => $label)
                                    <option value="{{ $valor }}" @selected($habitual?->value === $valor)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </details>
                    </form>

                    <details class="group">
                        <summary class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 w-full cursor-pointer list-none rounded-xl bg-white px-3 py-3 text-center text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50 dark:bg-ink-800 dark:text-red-300 dark:ring-red-500/40">
                            No entregado
                        </summary>
                        <form method="POST" action="{{ route($prefijoEntregas.'update', [$salida, $entrega]) }}" class="mt-2 space-y-1.5">
                            @csrf @method('PATCH')
                            <input type="hidden" name="resultado" value="no_entregado">
                            @foreach ($motivos as $valor => $texto)
                                @continue($valor === 'otro')
                                <button name="motivo_no_entrega" value="{{ $valor }}"
                                        class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 block w-full rounded-lg bg-gray-50 px-3 py-2 text-left text-sm text-gray-700 hover:bg-red-50 hover:text-red-700 dark:bg-ink-700 dark:text-paper-100">
                                    {{ $texto }}
                                </button>
                            @endforeach
                            <div class="flex gap-1.5">
                                <input name="nota" maxlength="300" placeholder="Otro motivo…" aria-label="Otro motivo de no entrega"
                                       class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                <button name="motivo_no_entrega" value="otro" class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-600 rounded-lg bg-gray-800 px-3 text-sm text-white dark:bg-paper-100 dark:text-ink-900">OK</button>
                            </div>
                        </form>
                    </details>
                </div>
            @endif

            @if ($puedeGestionar && $entrega->estaPendiente() && $abierta)
                <form method="POST" action="{{ route('rutas.salidas.entregas.destroy', [$salida, $entrega]) }}" class="mt-2 text-right">
                    @csrf @method('DELETE')
                    <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 text-xs text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500">Quitar de la salida</button>
                </form>
            @endif
        </div>
    @endforeach
@empty
    <div class="{{ $caja }} mt-4 px-5 py-10 text-center">
        <p class="text-base font-medium text-gray-800 dark:text-paper-100">Esta salida no lleva CCF</p>
        <p class="mt-1 text-sm text-gray-500 dark:text-paper-300">No había CCF pendientes para esta ruta cuando se creó.</p>
    </div>
@endforelse

@if ($modoVendedor || $puedeGestionar)
    @include('rutas.salidas._gastos')
    @if ($diaCerrado)
        @include('rutas.cierres._cerrado', ['cierre' => $cierreHoy, 'fecha' => $fechaHoy, 'prefijo' => $prefijoCierre])
    @elseif (($registrable || (! $modoVendedor && $salida->estado === \App\Enums\EstadoSalidaRuta::Finalizada)) && ($salida->entregas()->entregadas()->whereDate('fecha_resultado', $fechaHoy)->exists() || $salida->gastos()->whereDate('fecha', $fechaHoy)->exists()))
        <a href="{{ route($prefijoCierre.'cierre', $salida) }}" class="mt-6 flex min-h-11 items-center justify-center rounded-xl bg-green-600 px-5 py-4 text-lg font-bold text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600">Terminar día</a>
    @endif
    @if ($modoVendedor)
        {{-- Al volver: lo que sobró lleva su motivo y el dinero de todos los días se entrega junto. --}}
        <a href="{{ route('rutas.mi-salida.terminar', $salida) }}" class="mt-3 flex min-h-11 items-center justify-center rounded-xl bg-gray-800 px-5 py-3 font-semibold text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900">Terminar ruta</a>
    @endif
    @if (! $modoVendedor)
        @foreach ($salida->cierres()->whereDate('fecha', '!=', $fechaHoy)->orderByDesc('fecha')->get() as $cierreAnterior)
            <a href="{{ route($prefijoCierre.'cierre', ['salida' => $salida, 'fecha' => $cierreAnterior->fecha->toDateString()]) }}" class="mt-3 flex min-h-11 items-center text-sm underline">Día {{ $cierreAnterior->fecha->format('d/m/Y') }}{{ $cierreAnterior->estaCerrado() ? '' : ' · Reabierta' }}</a>
        @endforeach
    @endif
@endif

{{-- ¿Falta algo? Lo poco frecuente va plegado. --}}
@if ($puedeGestionar && $abierta)
    <details class="{{ $caja }} mt-4 px-5 py-4">
        <summary class="cursor-pointer text-sm font-medium text-gray-600 dark:text-paper-300">¿Falta un CCF?</summary>
        <div class="mt-3 space-y-3">
            <form method="POST" action="{{ route('rutas.salidas.entregas.cargar', $salida) }}">
                @csrf
                <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 w-full rounded-lg bg-gray-100 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-100">Buscar CCF nuevos de la ruta</button>
            </form>
            <form method="POST" action="{{ route('rutas.salidas.entregas.store', $salida) }}" class="flex gap-2">
                @csrf
                <input name="numero_control" value="{{ old('numero_control') }}" placeholder="Número de control del CCF" aria-label="Número de control del CCF"
                       class="min-w-0 flex-1 rounded-lg border-gray-300 font-mono text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                <div class="w-28">
                    <label for="ejercicio-entrega" class="sr-only">Año</label>
                    <input id="ejercicio-entrega" type="number" name="ejercicio" value="{{ old('ejercicio') }}" placeholder="Año" aria-label="Año" aria-describedby="ejercicio-entrega-ayuda" min="2026" max="2100"
                           class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                    <p id="ejercicio-entrega-ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Solo si el número existe en más de un año</p>
                </div>
                <button class="min-h-11 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 rounded-lg bg-gray-800 px-4 text-sm font-medium text-white dark:bg-paper-100 dark:text-ink-900">Agregar</button>
            </form>
        </div>
    </details>
@endif

