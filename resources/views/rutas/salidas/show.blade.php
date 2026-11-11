<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">{{ $salida->esViaje() ? 'Viaje' : 'Hoja de la salida' }}</h1>
            <a href="{{ route('rutas.dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-500 dark:bg-ink-800 dark:text-paper-100">Volver al tablero</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $puedeGestionar = auth()->user()?->can('rutas.gestionar');
        $hechos = $resumen['entregados'] + $resumen['no_entregados'];
        $porcentaje = $resumen['total'] ? (int) round($hechos * 100 / $resumen['total']) : 0;
        $planificada = $salida->estado === \App\Enums\EstadoSalidaRuta::Planificada;
        // Programada para otro día: sale ese día, como «Empezar ruta» de Mi salida.
        $futura = $planificada && $salida->fecha_inicio->toDateString() > \App\Support\HoraNegocio::fechaHoy();
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ===================== Encabezado ===================== --}}
            <div class="{{ $caja }} p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-2xl font-semibold text-gray-800 dark:text-paper-100">{{ $salida->titulo() }}</p>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-paper-300">
                            {{ $salida->periodoLegible() }} · {{ $salida->quienesVan() ?: 'Sin vendedores' }}
                        </p>
                        @if ($salida->proposito->sacaPedido() && auth()->user()->can('rutas.pedidos.ver'))
                            @php($pedidosSacados = $salida->pedidos()->vigentes()->count())
                            <a href="{{ route('rutas.pedidos.index', ['salida' => $salida->id]) }}" class="mt-1 inline-flex min-h-11 items-center text-sm text-indigo-600 underline dark:text-indigo-400">
                                {{ $pedidosSacados === 1 ? '1 pedido sacado' : $pedidosSacados.' pedidos sacados' }} · Ver pedidos
                            </a>
                        @endif
                    </div>
                    <x-rutas.estado-badge :estado="$salida->estado" :texto="$salida->estadoLegible()" />
                </div>

                <div class="mt-4">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-gray-600 dark:text-paper-300">
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['entregados'] }}</strong> entregados ·
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['no_entregados'] }}</strong> no entregado{{ $resumen['no_entregados'] === 1 ? '' : 's' }} ·
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['sin_registrar'] }}</strong> por marcar
                        </span>
                        <span class="text-gray-400 dark:text-paper-500">{{ $resumen['total'] }} CCF</span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-ink-700">
                        <div class="h-2.5 rounded-full bg-green-500" style="width: {{ $porcentaje }}%"></div>
                    </div>
                </div>

                {{-- Las salidas de antes ya no se terminan ni se liquidan acá (11/11/2026): quedan para leer. --}}
                @if (! $salida->estado->esTerminal() && ($puedeGestionar || $salida->esAutomatica()))
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        @if ($futura)
                            <p class="flex-1 rounded-lg bg-gray-50 px-4 py-3 text-center text-sm font-medium text-gray-700 dark:bg-ink-700 dark:text-paper-100">
                                Sale el {{ $salida->fecha_inicio->copy()->locale('es')->dayName }} {{ $salida->fecha_inicio->format('d/m/Y') }}
                            </p>
                        @elseif ($planificada)
                            <form method="POST" action="{{ route('rutas.salidas.iniciar', $salida) }}" class="flex-1">
                                @csrf @method('PATCH')
                                <button class="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-medium text-white hover:bg-indigo-700">Salir ahora</button>
                            </form>
                        @elseif ($salida->esAutomatica())
                            {{-- El viaje no se liquida acá. --}}
                            <p class="flex-1 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:bg-ink-700 dark:text-paper-100">Se cierra solo cuando todo está entregado. El dinero se entrega con el PDF del día, firmado.</p>
                        @endif
                        @if ($puedeGestionar)
                            <form method="POST" action="{{ route('rutas.salidas.cancelar', $salida) }}"
                                  onsubmit="return confirm('{{ $salida->esViaje() ? '¿Cancelar este viaje?' : '¿Cancelar esta salida?' }} Sus CCF vuelven a quedar pendientes.');">
                                @csrf @method('PATCH')
                                <button class="text-sm text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500">Cancelar</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($salida->observaciones)
                    <p class="mt-4 whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-600 dark:border-ink-700 dark:text-paper-300">{{ $salida->observaciones }}</p>
                @endif
            </div>

            {{-- El viaje no se liquida: el control del dinero es el PDF de cada día, firmado. --}}
            @if (! empty($diasPdf))
                <section aria-labelledby="dias-pdf-titulo" class="{{ $caja }} mt-4 p-5">
                    <h2 id="dias-pdf-titulo" class="text-base font-semibold text-gray-800 dark:text-paper-100">PDF de cada día</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">El resumen que firma el vendedor al entregar el dinero: lo entregado, lo cobrado y sus gastos.</p>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-ink-700">
                        @foreach ($diasPdf as $diaPdf)
                            <li>
                                <a href="{{ route('rutas.salidas.dia-pdf', [$salida, $diaPdf['fecha'], $diaPdf['persona']]) }}" target="_blank" rel="noopener"
                                   class="flex min-h-11 flex-wrap items-center justify-between gap-x-3 py-2 text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{-- La fecha se arma en la misma línea: un bloque de PHP acá se confunde con el de una línea de más arriba. --}}
                                    <span>{{ ucfirst(\Illuminate\Support\Carbon::parse($diaPdf['fecha'])->locale('es')->dayName) }} {{ \Illuminate\Support\Carbon::parse($diaPdf['fecha'])->format('d/m/Y') }} · {{ $diaPdf['persona']->nombre }}</span>
                                    <span class="font-medium">Ver PDF</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @include('rutas.salidas._entregas')

        </div>
    </div>
</x-app-layout>
