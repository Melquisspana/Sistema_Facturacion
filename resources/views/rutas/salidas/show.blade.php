<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Hoja de la salida</h2>
            <a href="{{ route('rutas.dashboard') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Volver a rutas</a>
        </div>
    </x-slot>

    @php
        $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';
        $puedeGestionar = auth()->user()?->can('rutas.gestionar');
        $hechos = $resumen['entregados'] + $resumen['no_entregados'];
        $porcentaje = $resumen['total'] ? (int) round($hechos * 100 / $resumen['total']) : 0;
        $planificada = $salida->estado === \App\Enums\EstadoSalidaRuta::Planificada;
    @endphp

    <div class="py-6 sm:py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            {{-- ===================== Encabezado ===================== --}}
            <div class="{{ $caja }} p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-2xl font-semibold text-gray-800 dark:text-paper-100">{{ $salida->ruta->nombre }}</p>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-paper-400">
                            {{ $salida->periodoLegible() }} · {{ $salida->participantes->map(fn ($p) => $p->personal?->nombre)->filter()->implode(' y ') ?: 'Sin vendedores' }}
                        </p>
                    </div>
                    <x-rutas.estado-badge :estado="$salida->estado" class="!px-3 !py-1 !text-sm" />
                </div>

                <div class="mt-4">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-gray-600 dark:text-paper-300">
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['entregados'] }}</strong> entregados ·
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['no_entregados'] }}</strong> no entregados ·
                            <strong class="text-gray-800 dark:text-paper-100">{{ $resumen['sin_registrar'] }}</strong> por marcar
                        </span>
                        <span class="text-gray-400 dark:text-paper-500">{{ $resumen['total'] }} CCF</span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-ink-700">
                        <div class="h-2.5 rounded-full bg-green-500" style="width: {{ $porcentaje }}%"></div>
                    </div>
                </div>

                @if ($puedeGestionar && ! $salida->estado->esTerminal())
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        @if ($planificada)
                            <form method="POST" action="{{ route('rutas.salidas.iniciar', $salida) }}" class="flex-1">
                                @csrf @method('PATCH')
                                <button class="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-medium text-white hover:bg-indigo-700">Salir ahora</button>
                            </form>
                        @else
                            {{-- Lo que quede por marcar vuelve solo a pendientes; se avisa antes. --}}
                            <form method="POST" action="{{ route('rutas.salidas.finalizar', $salida) }}" class="flex-1"
                                  @if ($resumen['sin_registrar'] > 0)
                                      onsubmit="return confirm('Quedan {{ $resumen['sin_registrar'] }} CCF por marcar. Al terminar vuelven a quedar pendientes para la próxima salida. ¿Terminar igual?');"
                                  @endif>
                                @csrf @method('PATCH')
                                <button class="w-full rounded-lg bg-gray-800 px-4 py-3 text-sm font-medium text-white hover:bg-gray-700 dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">Terminar salida</button>
                            </form>
                        @endif
                        <a href="{{ route('rutas.salidas.edit', $salida) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cambiar quiénes van</a>
                        <form method="POST" action="{{ route('rutas.salidas.cancelar', $salida) }}"
                              onsubmit="return confirm('¿Cancelar esta salida? Sus CCF vuelven a quedar pendientes.');">
                            @csrf @method('PATCH')
                            <button class="text-sm text-gray-400 hover:text-red-600 hover:underline dark:text-paper-500">Cancelar</button>
                        </form>
                    </div>
                @endif

                @if ($salida->observaciones)
                    <p class="mt-4 whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-600 dark:border-ink-700 dark:text-paper-300">{{ $salida->observaciones }}</p>
                @endif
            </div>

            @include('rutas.salidas._entregas')

        </div>
    </div>
</x-app-layout>
