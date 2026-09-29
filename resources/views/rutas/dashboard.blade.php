<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Rutas</h2>
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('rutas.salidas.index') }}" class="text-gray-500 hover:underline dark:text-paper-400">Salidas anteriores</a>
                <a href="{{ route('rutas.rutas.index') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Configurar rutas</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <x-rutas.avisos />

            @php
                $R = \App\Services\Rutas\RitmoRutas::class;

                $caja = 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none';

                // [etiqueta, insignia, borde, barra]. Clases completas para que Tailwind las vea.
                $tono = [
                    $R::ATRASADA => ['Toca ir', 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300', 'ring-red-300 dark:ring-red-500/40', 'bg-red-500'],
                    $R::PRONTO => ['Ya casi', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300', 'ring-amber-300 dark:ring-amber-500/40', 'bg-amber-500'],
                    $R::AL_DIA => ['Al día', 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300', '', 'bg-green-500'],
                    $R::EN_RUTA => ['En camino', 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', 'ring-sky-300 dark:ring-sky-500/40', 'bg-sky-500'],
                    $R::SIN_OBJETIVO => ['Sin frecuencia', 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300', '', 'bg-gray-400'],
                    $R::SIN_SALIDAS => ['Sin salidas', 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300', '', 'bg-gray-400'],
                ];
            @endphp

            {{-- ===================== En camino ===================== --}}
            @foreach ($enCamino as ['salida' => $salida, 'resumen' => $resumen])
                @php $hechos = $resumen['entregados'] + $resumen['no_entregados']; @endphp
                <a href="{{ route('rutas.salidas.show', $salida) }}"
                   class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-sky-50 px-5 py-4 ring-1 ring-sky-200 transition hover:ring-sky-400 dark:bg-sky-500/10 dark:ring-sky-500/30">
                    <div class="min-w-0">
                        <p class="text-base font-semibold text-sky-900 dark:text-sky-200">
                            {{ $salida->estado === \App\Enums\EstadoSalidaRuta::EnCurso ? 'En camino' : 'Programada' }}: {{ $salida->ruta->nombre }}
                            <span class="font-normal text-sky-700 dark:text-sky-300">· {{ $salida->fecha_inicio->isToday() ? 'hoy' : 'desde el '.$salida->fecha_inicio->translatedFormat('d M') }}</span>
                        </p>
                        <p class="text-sm text-sky-700 dark:text-sky-300">
                            {{ $salida->personal->pluck('nombre')->implode(' y ') ?: 'Sin vendedores' }}
                            · {{ $resumen['entregados'] }} de {{ $resumen['total'] }} entregados
                            @if ($resumen['no_entregados'] > 0) · {{ $resumen['no_entregados'] }} no entregados @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="hidden h-2 w-32 overflow-hidden rounded-full bg-sky-100 sm:block dark:bg-sky-500/20">
                            <div class="h-2 rounded-full bg-sky-500" style="width: {{ $resumen['total'] ? round($hechos * 100 / $resumen['total']) : 0 }}%"></div>
                        </div>
                        <span class="text-sm font-medium text-sky-800 dark:text-sky-200">Abrir hoja →</span>
                    </div>
                </a>
            @endforeach

            {{-- ===================== Una tarjeta por ruta ===================== --}}
            @if ($filas->isEmpty())
                <div class="{{ $caja }} px-6 py-12 text-center">
                    <p class="text-base font-medium text-gray-800 dark:text-paper-100">Creá tu primera ruta</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-paper-400">Nombre, cada cuántos días se va y qué lugares cubre.</p>
                    @can('rutas.gestionar')
                        <a href="{{ route('rutas.rutas.index') }}" class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Configurar rutas</a>
                    @endcan
                </div>
            @else
                <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($filas as $fila)
                        @php
                            [$texto, $insignia, $borde, $barra] = $tono[$fila['estado']];
                            $ruta = $fila['ruta'];
                            $pend = $pendientes[$ruta->id] ?? ['cantidad' => 0, 'monto' => 0];
                            $faltan = $fila['faltan'];
                        @endphp
                        <div class="{{ $caja }} {{ $borde }} flex flex-col p-5">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-lg font-semibold text-gray-800 dark:text-paper-100">{{ $ruta->nombre }}</p>
                                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $insignia }}">{{ $texto }}</span>
                            </div>

                            {{-- El número grande: cuánto falta o cuánto se pasó. --}}
                            <p class="mt-3 text-2xl font-semibold text-gray-800 dark:text-paper-100">
                                @if ($fila['enCurso'])
                                    En camino
                                @elseif ($fila['dias'] === null)
                                    Nunca se ha ido
                                @elseif ($faltan === null)
                                    Hace {{ $fila['dias'] }} {{ $fila['dias'] === 1 ? 'día' : 'días' }}
                                @elseif ($faltan < 0)
                                    {{ abs($faltan) }} {{ abs($faltan) === 1 ? 'día' : 'días' }} tarde
                                @elseif ($faltan === 0)
                                    Toca hoy
                                @else
                                    Faltan {{ $faltan }} {{ $faltan === 1 ? 'día' : 'días' }}
                                @endif
                            </p>
                            <p class="text-sm text-gray-500 dark:text-paper-400">
                                Última: {{ $fila['ultima']?->fecha_fin_real?->translatedFormat('d M') ?? $fila['ultima']?->fecha_inicio?->translatedFormat('d M') ?? '—' }}
                                @if ($ruta->frecuencia_objetivo_dias) · cada {{ $ruta->frecuencia_objetivo_dias }} días @endif
                            </p>

                            @if ($fila['avance'] !== null && ! $fila['enCurso'])
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-ink-700">
                                    <div class="h-2 rounded-full {{ $barra }}" style="width: {{ max(4, $fila['avance']) }}%"></div>
                                </div>
                            @endif

                            <p class="mt-3 text-sm {{ $pend['cantidad'] > 0 ? 'font-medium text-gray-800 dark:text-paper-100' : 'text-gray-400 dark:text-paper-500' }}">
                                @if ($pend['cantidad'] > 0)
                                    {{ $pend['cantidad'] }} CCF por entregar · ${{ number_format($pend['monto'], 2) }}
                                @else
                                    Sin CCF por entregar
                                @endif
                                <span class="font-normal text-gray-400 dark:text-paper-500">· {{ $ruta->sucursales_count }} salas</span>
                            </p>

                            {{-- Salir a esta ruta: se eligen quiénes van y listo. --}}
                            @can('rutas.gestionar')
                                <div class="mt-auto pt-4">
                                    @if ($fila['enCurso'])
                                        <a href="{{ route('rutas.salidas.show', $fila['enCurso']) }}"
                                           class="block rounded-lg bg-sky-600 px-4 py-2.5 text-center text-sm font-medium text-white hover:bg-sky-700">Abrir hoja de la salida</a>
                                    @elseif ($vendedores->isEmpty())
                                        <a href="{{ route('rutas.rutas.index') }}#vendedores"
                                           class="block rounded-lg bg-gray-100 px-4 py-2.5 text-center text-sm text-gray-600 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-300">Agregá un vendedor para salir</a>
                                    @else
                                        <details class="group">
                                            <summary class="cursor-pointer list-none rounded-lg bg-indigo-600 px-4 py-2.5 text-center text-sm font-medium text-white hover:bg-indigo-700 group-open:rounded-b-none">
                                                Salir a esta ruta
                                            </summary>
                                            <form method="POST" action="{{ route('rutas.salidas.store') }}"
                                                  class="space-y-3 rounded-b-lg border border-t-0 border-indigo-200 p-3 dark:border-indigo-500/30">
                                                @csrf
                                                <input type="hidden" name="ruta_id" value="{{ $ruta->id }}">
                                                <p class="text-xs font-medium text-gray-500 dark:text-paper-400">¿Quiénes van?</p>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ($vendedores as $v)
                                                        <label class="cursor-pointer">
                                                            <input type="checkbox" name="personal[]" value="{{ $v->id }}" class="peer sr-only" @checked($vendedores->count() === 1)>
                                                            <span class="inline-block rounded-full px-3 py-1.5 text-sm ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 dark:ring-ink-500 dark:text-paper-200">{{ $v->nombre }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <button class="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700">
                                                    Salir hoy{{ $pend['cantidad'] > 0 ? ' con '.$pend['cantidad'].' CCF' : '' }}
                                                </button>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($salasSinRuta > 0)
                <a href="{{ route('rutas.rutas.index') }}"
                   class="mt-5 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <span><strong>{{ $salasSinRuta }} {{ $salasSinRuta === 1 ? 'sala' : 'salas' }} sin ruta.</strong> No cuentan en ninguna ruta hasta asignarlas.</span>
                    <span class="shrink-0 font-medium">Asignar →</span>
                </a>
            @endif

        </div>
    </div>
</x-app-layout>
