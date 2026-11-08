{{-- «Terminar ruta», para la oficina y para el vendedor ($modoVendedor, $prefijo). La regla
     vive en LiquidacionSalida; acá solo se dibuja. --}}
@php
    $caja = 'rounded-2xl bg-white p-5 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $plata = fn (int $centavos) => '$'.number_format($centavos / 100, 2);
    $enCurso = $salida->estado === \App\Enums\EstadoSalidaRuta::EnCurso;
@endphp

<div class="space-y-5">
    <div>
        {{-- El vendedor que entró sin querer (buscaba marcar entregas) vuelve con un botón grande. --}}
        @unless ($modoVendedor && $enCurso)
            <a href="{{ $modoVendedor ? route('rutas.mi-salida.index') : route('rutas.salidas.show', $salida) }}" class="inline-flex min-h-11 items-center text-sm underline dark:text-paper-300">Volver a la salida</a>
        @endunless
        <h1 class="text-3xl font-bold dark:text-paper-100">{{ $salida->ruta->nombre }}</h1>
        <p class="mt-1 text-gray-600 dark:text-paper-300">{{ $salida->periodoLegible() }} · {{ $salida->quienesVan() ?: 'Sin vendedores' }}</p>
        @if ($modoVendedor && $enCurso)
            <a href="{{ route('rutas.mi-salida.show', $salida) }}" class="mt-4 flex min-h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-4 text-lg font-bold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Seguir marcando entregas</a>
        @endif
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800 dark:bg-ink-800 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    {{-- Lo que dejó cada día terminado --}}
    <section class="{{ $caja }}" aria-label="Días de la ruta">
        <h2 class="text-lg font-bold dark:text-paper-100">Días</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm dark:text-paper-100">
                <thead><tr class="text-left text-gray-600 dark:text-paper-300"><th class="py-2 pr-3">Día</th><th class="py-2 pr-3 text-right">Efectivo</th><th class="py-2 pr-3 text-right">Cheques</th><th class="py-2 pr-3 text-right">Transf.</th><th class="py-2 text-right">Gastos</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                    @forelse ($totales['dias'] as $dia)
                        <tr>
                            <td class="py-2 pr-3">
                                @if ($modoVendedor)
                                    {{ $dia->fecha->format('d/m/Y') }}
                                @else
                                    <a href="{{ route('rutas.salidas.cierre', ['salida' => $salida, 'fecha' => $dia->fecha->toDateString()]) }}" class="underline">{{ $dia->fecha->format('d/m/Y') }}</a>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">${{ number_format((float) $dia->efectivo_cobrado, 2) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">${{ number_format((float) $dia->cheques_cobrado, 2) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">${{ number_format((float) $dia->transferencias_cobrado, 2) }}</td>
                            <td class="py-2 text-right tabular-nums">−${{ number_format((float) $dia->gastos_total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-600 dark:text-paper-300">Ningún día terminado todavía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- El vendedor termina solo el día de hoy; un día anterior lo termina la oficina. --}}
        @foreach ($diasSinTerminar as $fecha)
            @if (! $modoVendedor || $fecha === \App\Support\HoraNegocio::fechaHoy())
                <a href="{{ route($prefijo.'cierre', ['salida' => $salida, 'fecha' => $fecha]) }}" class="mt-3 flex min-h-11 items-center text-sm font-semibold text-amber-800 underline dark:text-amber-300">Falta terminar el día {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}</a>
            @else
                <p class="mt-3 text-sm font-semibold text-amber-800 dark:text-amber-300">Falta terminar el día {{ \Illuminate\Support\Carbon::parse($fecha)->format('d/m/Y') }}: pedile a la oficina que lo termine.</p>
            @endif
        @endforeach
    </section>

    {{-- Totales de toda la ruta --}}
    <section class="{{ $caja }}" aria-label="Liquidación">
        <dl class="grid grid-cols-3 gap-2 sm:gap-3">
            <div><dt class="text-xs text-gray-600 sm:text-sm dark:text-paper-300">Efectivo</dt><dd class="text-lg font-bold tabular-nums dark:text-paper-100 sm:text-2xl">{{ $plata($totales['efectivo']) }}</dd></div>
            <div><dt class="text-xs text-gray-600 sm:text-sm dark:text-paper-300">Cheques</dt><dd class="text-lg font-bold tabular-nums dark:text-paper-100 sm:text-2xl">{{ $plata($totales['cheques']) }}</dd></div>
            <div><dt class="text-xs text-gray-600 sm:text-sm dark:text-paper-300">Transferencias</dt><dd class="text-lg font-bold tabular-nums dark:text-paper-100 sm:text-2xl">{{ $plata($totales['transferencias']) }}</dd></div>
        </dl>
        <p class="mt-4 dark:text-paper-300">Gastos de todos los días −{{ $plata($totales['gastos']) }}</p>
        <p class="mt-2 text-2xl font-bold text-green-900 dark:text-green-300">{{ $totales['a_entregar'] < 0 ? 'A reintegrar al vendedor' : 'Efectivo a entregar' }} {{ $plata(abs($totales['a_entregar'])) }}</p>
        @if ($totales['cheques'] > 0)
            <p class="mt-1 text-sm dark:text-paper-300">Más los cheques por {{ $plata($totales['cheques']) }}.</p>
        @endif
        @if ($salida->estaLiquidada())
            <p class="mt-4 text-sm dark:text-paper-300">Ruta terminada el {{ $salida->liquidada_en->format('d/m/Y H:i') }} por {{ $salida->liquidadaPor?->name }}. Entrega el dinero: {{ $salida->entregaDinero?->nombre ?? 'sin cobros' }}. Recibe: Oficina.</p>
            <a href="{{ route($prefijo.'liquidacion-final', $salida) }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-green-700 px-5 py-3 font-semibold text-white hover:bg-green-800">Descargar liquidación de la ruta</a>
        @endif
    </section>

    @if ($noEntregadas->isNotEmpty())
        <section class="{{ $caja }}" aria-label="No entregados">
            <h2 class="text-lg font-bold dark:text-paper-100">No entregados</h2>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-ink-700 dark:text-paper-100">
                @foreach ($noEntregadas as $entrega)
                    <li class="py-2">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }} · …{{ \App\Support\OrdenCompra::ultimosDigitos($entrega->dte->numero_control) }} · {{ $entrega->motivo_no_entrega?->label() }}{{ $entrega->nota ? ': '.$entrega->nota : '' }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- En curso, o una automática de «Por entregar» que se finalizó sola sin liquidar. --}}
    @if (\App\Services\Rutas\LiquidacionSalida::seLiquida($salida) && ($enCurso || ! $modoVendedor))
        <form method="POST" action="{{ route($prefijo.'liquidar', $salida) }}" class="space-y-5">
            @csrf
            @if ($sinMarcar->isNotEmpty())
                <section class="{{ $caja }}" aria-label="Sobraron">
                    <h2 class="text-lg font-bold dark:text-paper-100">Sobraron {{ $sinMarcar->count() }}: ¿por qué no se entregaron?</h2>
                    @foreach ($sinMarcar as $entrega)
                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-ink-700">
                            <p class="font-semibold dark:text-paper-100" title="{{ $entrega->dte->numero_control }}">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }} · …{{ \App\Support\OrdenCompra::ultimosDigitos($entrega->dte->numero_control) }} · ${{ number_format((float) $entrega->dte->total_pagar, 2) }}</p>
                            <label for="motivo-{{ $entrega->id }}" class="sr-only">Motivo</label>
                            <select id="motivo-{{ $entrega->id }}" name="motivos[{{ $entrega->id }}][motivo]" required class="min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                                <option value="">Elegí el motivo</option>
                                @foreach ($motivos as $valor => $texto)
                                    <option value="{{ $valor }}" @selected(old('motivos.'.$entrega->id.'.motivo') === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                            <label for="nota-{{ $entrega->id }}" class="sr-only">Detalle</label>
                            <input id="nota-{{ $entrega->id }}" name="motivos[{{ $entrega->id }}][nota]" value="{{ old('motivos.'.$entrega->id.'.nota') }}" maxlength="300" placeholder="Detalle (obligatorio con «Otro»)" class="min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                        </div>
                    @endforeach
                </section>
            @endif

            <div>
                <label for="entrega-dinero" class="block font-semibold dark:text-paper-100">¿Quién entrega el dinero?</label>
                <select id="entrega-dinero" name="entrega_dinero_personal_id" @required($totales['efectivo'] + $totales['cheques'] + $totales['transferencias'] > 0) class="mt-2 min-h-11 w-full rounded-xl border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                    <option value="">Elegí una persona</option>
                    @foreach ($participantes as $persona)
                        <option value="{{ $persona->id }}" @selected((string) old('entrega_dinero_personal_id', $participantes->count() === 1 ? $persona->id : null) === (string) $persona->id)>{{ $persona->nombre }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">Obligatorio si hay algo cobrado.</p>
            </div>

            <button class="min-h-11 w-full rounded-xl bg-green-600 px-5 py-4 text-lg font-bold text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600"
                    onclick="return confirm('¿Terminar la ruta? Después ya no se cambian sus días, entregas ni gastos.');">Terminar ruta</button>
        </form>
    @endif
</div>
