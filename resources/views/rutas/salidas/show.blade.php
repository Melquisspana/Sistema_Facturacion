<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Hoja de la salida</h2>
            <a href="{{ route('rutas.dashboard') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Volver a rutas</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $puedeGestionar = auth()->user()?->can('rutas.gestionar');
        // El jefe de vendedores termina días y rutas, pero no edita, cancela ni reabre.
        $puedeTerminar = $puedeGestionar || auth()->user()?->can('rutas.salidas.terminar');
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
                        <p class="text-2xl font-semibold text-gray-800 dark:text-paper-100">{{ $salida->ruta->nombre }}</p>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-paper-300">
                            {{ $salida->periodoLegible() }} · {{ $salida->quienesVan() ?: 'Sin vendedores' }}
                        </p>
                        <p class="mt-0.5 text-sm font-medium text-gray-700 dark:text-paper-100">{{ $salida->proposito->descripcion() }}</p>
                        @if ($salida->proposito->sacaPedido() && auth()->user()->can('rutas.pedidos.ver'))
                            @php($pedidosSacados = $salida->pedidos()->registrados()->count())
                            <a href="{{ route('rutas.pedidos.index', ['salida' => $salida->id]) }}" class="mt-1 inline-flex min-h-11 items-center text-sm text-indigo-600 underline dark:text-indigo-400">
                                {{ $pedidosSacados === 1 ? '1 pedido sacado' : $pedidosSacados.' pedidos sacados' }} · Ver pedidos
                            </a>
                        @endif
                    </div>
                    <x-rutas.estado-badge :estado="$salida->estado" class="!px-3 !py-1 !text-sm" />
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

                @if (($puedeGestionar || ($puedeTerminar && ! $planificada)) && ! $salida->estado->esTerminal())
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
                        @else
                            {{-- Terminar ruta: lo que sobró lleva su motivo y se liquida todo junto. --}}
                            <a href="{{ route('rutas.salidas.terminar', $salida) }}" class="flex-1 rounded-lg bg-gray-800 px-4 py-3 text-center text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Terminar ruta</a>
                        @endif
                        @if ($puedeGestionar)
                            <a href="{{ route('rutas.salidas.edit', $salida) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Cambiar quiénes van</a>
                            <form method="POST" action="{{ route('rutas.salidas.cancelar', $salida) }}"
                                  onsubmit="return confirm('¿Cancelar esta salida? Sus CCF vuelven a quedar pendientes.');">
                                @csrf @method('PATCH')
                                <button class="text-sm text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500">Cancelar</button>
                            </form>
                        @endif
                    </div>
                @endif

                {{-- La automática de «Por entregar» se finaliza sola al pasar el día; la oficina la termina igual. --}}
                @if ($puedeTerminar && $salida->estado === \App\Enums\EstadoSalidaRuta::Finalizada && \App\Services\Rutas\LiquidacionSalida::seLiquida($salida))
                    <a href="{{ route('rutas.salidas.terminar', $salida) }}" class="mt-5 flex min-h-11 items-center justify-center rounded-lg bg-gray-800 px-4 py-3 text-center text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Terminar ruta</a>
                @endif
                @if ($puedeTerminar && $salida->estaLiquidada())
                    <a href="{{ route('rutas.salidas.terminar', $salida) }}" class="mt-5 flex min-h-11 items-center justify-center rounded-lg bg-green-700 px-4 py-3 text-sm font-medium text-white hover:bg-green-800">Liquidación de la ruta</a>
                @endif

                @if ($salida->observaciones)
                    <p class="mt-4 whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-600 dark:border-ink-700 dark:text-paper-300">{{ $salida->observaciones }}</p>
                @endif
            </div>

            @include('rutas.salidas._entregas')

            @include('rutas.salidas._facturados_despues')

        </div>
    </div>
</x-app-layout>
