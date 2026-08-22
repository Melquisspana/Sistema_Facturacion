{{-- Reporte de jornadas: qué ocurrió cada día, según las marcaciones.

     ESTA VISTA NO CALCULA NADA. Recibe objetos `Jornada` ya resueltos por
     App\Support\Asistencia\ConsultaJornadas — la misma capa que consumirá el
     módulo de Formatos. Emparejar marcaciones dentro de un Blade sería
     garantizar que la pantalla y el documento acaben discrepando.

     LO QUE NO HAY, Y NO FALTA: tardanzas, horas extra, ausencias y feriados.
     Necesitan una hora oficial de entrada, una jornada pactada y un calendario
     laboral; ninguna existe todavía, y mostrarlas sería inventar la regla que
     después alguien discutiría en una planilla.

     Un día SIN marcaciones no aparece: no es «ausencia» —eso presupone saber que
     ese día se trabajaba— sino un día del que no hay nada que decir. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Jornadas</h2>
    </x-slot>

    @php
        $tarjeta = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
        $campo = 'mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
        $etiqueta = 'block text-xs font-medium text-gray-500 dark:text-paper-400';
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            <x-asistencia.avisos />

            {{-- ─────────────────────────────── Filtros ─────────────────────────────── --}}
            <form method="GET" action="{{ route('asistencia.jornadas.index') }}"
                  class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="lg:col-span-2">
                        <label for="empleado_id" class="{{ $etiqueta }}">Empleado</label>
                        <select id="empleado_id" name="empleado_id" class="{{ $campo }}">
                            <option value="">Todos</option>
                            @foreach ($empleados as $empleado)
                                <option value="{{ $empleado->id }}" @selected($filtro->empleadoId === $empleado->id)>
                                    {{ $empleado->nombreCompleto() }}@unless ($empleado->activo) (inactivo)@endunless
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="desde" class="{{ $etiqueta }}">Desde</label>
                        <input id="desde" type="date" name="desde" value="{{ $filtro->desde?->format('Y-m-d') }}" class="{{ $campo }}">
                        @error('desde') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="hasta" class="{{ $etiqueta }}">Hasta</label>
                        <input id="hasta" type="date" name="hasta" value="{{ $filtro->hasta?->format('Y-m-d') }}" class="{{ $campo }}">
                        @error('hasta') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="dispositivo_id" class="{{ $etiqueta }}">Lector</label>
                        <select id="dispositivo_id" name="dispositivo_id" class="{{ $campo }}">
                            <option value="">Todos</option>
                            @foreach ($lectores as $lector)
                                <option value="{{ $lector->id }}" @selected($filtro->dispositivoId === $lector->id)>{{ $lector->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="estado" class="{{ $etiqueta }}">Estado</label>
                        <select id="estado" name="estado" class="{{ $campo }}">
                            <option value="">Todos</option>
                            @foreach ($estados as $unEstado)
                                <option value="{{ $unEstado->value }}" @selected($estado === $unEstado)>{{ $unEstado->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                    <a href="{{ route('asistencia.jornadas.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Mes en curso</a>
                    <span class="ml-auto text-xs text-gray-400 dark:text-paper-500">
                        Días completos en {{ $zona }}, ambos extremos incluidos. Sin rango se muestra el mes en curso.
                    </span>
                </div>
            </form>

            {{-- ─────────────────────────────── Resumen ─────────────────────────────── --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="{{ $tarjeta }}">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400">Jornadas</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ $resumen['jornadas'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                        {{ $resumen['personas'] }} persona(s) &middot; {{ $resumen['dias'] }} día(s)
                    </p>
                </div>

                <div class="{{ $tarjeta }}">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400">Tiempo de presencia</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">
                        {{ number_format($resumen['trabajado_horas'], 2) }} h
                    </p>
                    {{-- La advertencia que hace honesto el total: con jornadas abiertas,
                         lo que se muestra es un mínimo. Un total sin esta marca invita
                         a tomarlo por definitivo. --}}
                    <p class="mt-1 text-xs {{ $resumen['tiempo_exacto'] ? 'text-gray-500 dark:text-paper-400' : 'text-amber-700 dark:text-amber-300' }}">
                        @if ($resumen['tiempo_exacto'])
                            suma exacta de los tramos cerrados
                        @else
                            mínimo: hay jornadas sin cerrar
                        @endif
                    </p>
                </div>

                <div class="{{ $tarjeta }}">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400">Completas</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-green-700 dark:text-green-300">{{ $resumen['completas'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">cada entrada con su salida</p>
                </div>

                <div class="{{ $tarjeta }}">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400">Requieren revisión</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-amber-700 dark:text-amber-300">
                        {{ $resumen['abiertas'] + $resumen['irregulares'] }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                        {{ $resumen['abiertas'] }} abierta(s) &middot; {{ $resumen['irregulares'] }} irregular(es)
                    </p>
                </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-paper-400">
                {{ $filtro->descripcion([
                    'empleado' => $empleados->firstWhere('id', $filtro->empleadoId)?->nombreCompleto(),
                    'dispositivo' => $lectores->firstWhere('id', $filtro->dispositivoId)?->nombre,
                ]) }}
                @if ($estado)
                    &middot; Solo {{ strtolower($estado->label()) }}s
                @endif
            </p>

            {{-- ─────────────────────────────── Listado ─────────────────────────────── --}}
            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-400">
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Empleado</th>
                                <th class="px-4 py-3">Primera entrada</th>
                                <th class="px-4 py-3">Última salida</th>
                                <th class="px-4 py-3">Tiempo</th>
                                <th class="px-4 py-3 text-center">Marcaciones</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3 text-right">Detalle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($jornadas as $jornada)
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="px-4 py-3 tabular-nums text-gray-800 dark:text-paper-200">{{ $jornada->fecha->format('d/m/Y') }}</td>

                                    <td class="px-4 py-3">
                                        @if ($jornada->empleado)
                                            <a href="{{ route('asistencia.empleados.show', $jornada->empleado) }}"
                                               class="text-gray-800 hover:underline dark:text-paper-200">{{ $jornada->empleado->nombreCompleto() }}</a>
                                            @unless ($jornada->empleado->activo)
                                                <span class="ml-1 text-xs text-gray-400 dark:text-paper-500">(inactivo)</span>
                                            @endunless
                                        @else
                                            <span class="text-gray-400 dark:text-paper-500">&mdash;</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 tabular-nums text-gray-800 dark:text-paper-200">
                                        {{ $jornada->primeraEntrada()?->marcado_at?->copy()?->setTimezone($zona)?->format('H:i') ?? '—' }}
                                    </td>

                                    <td class="px-4 py-3 tabular-nums text-gray-800 dark:text-paper-200">
                                        {{ $jornada->ultimaSalida()?->marcado_at?->copy()?->setTimezone($zona)?->format('H:i') ?? '—' }}
                                    </td>

                                    <td class="px-4 py-3 tabular-nums">
                                        <span class="font-medium text-gray-800 dark:text-paper-100">{{ $jornada->trabajadoLegible() }}</span>
                                        @unless ($jornada->tiempoEsExacto())
                                            {{-- Suma de los tramos que SÍ cerraron. No se inventa una
                                                 hora de salida para el que quedó abierto. --}}
                                            <span class="block text-xs text-amber-700 dark:text-amber-300" title="Hay una entrada sin salida: el total es un mínimo">
                                                al menos
                                            </span>
                                        @endunless
                                    </td>

                                    <td class="px-4 py-3 text-center tabular-nums text-gray-600 dark:text-paper-300">
                                        {{ $jornada->totalMarcaciones() }}
                                        <span class="block text-xs text-gray-400 dark:text-paper-500">
                                            {{ $jornada->paresCompletos() }} par(es)
                                        </span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $jornada->estado->clases() }}"
                                              title="{{ $jornada->estado->explicacion() }}">{{ $jornada->estado->label() }}</span>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        {{-- Al historial ya filtrado por esa persona y ese día: el
                                             detalle de una jornada son sus marcaciones, y esa pantalla
                                             ya existe. --}}
                                        <a href="{{ route('asistencia.marcaciones.index', [
                                               'empleado_id' => $jornada->empleadoId,
                                               'desde' => $jornada->fecha->format('Y-m-d'),
                                               'hasta' => $jornada->fecha->format('Y-m-d'),
                                           ]) }}"
                                           class="text-indigo-600 hover:underline dark:text-indigo-400">Ver marcaciones</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        No hay marcaciones en este rango, así que no hay jornadas que mostrar.
                                        <span class="mt-1 block text-xs">
                                            Los días sin marcaciones no aparecen: el sistema todavía no sabe qué días
                                            se trabaja.
                                        </span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($jornadas->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3 dark:border-ink-700">{{ $jornadas->links() }}</div>
                @endif
            </div>

            <div class="rounded-xl bg-white p-5 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 dark:text-paper-300">
                <h2 class="text-base font-semibold text-gray-800 dark:text-paper-100">Cómo leer este reporte</h2>
                <ul class="mt-2 space-y-1.5">
                    <li><strong>Tiempo</strong> es la suma de los tramos <em>entrada → salida</em>, no la resta entre
                        la primera entrada y la última salida. Quien entra a las 07:00, sale a las 12:00, vuelve a
                        las 13:00 y se va a las 16:00 tiene <strong>8 h</strong>, no 9.</li>
                    <li><strong>Abierta</strong> significa que quedó una entrada sin salida. El tiempo mostrado es un
                        mínimo. Es lo que ocurre con un olvido &mdash; y también con un turno que cruza la medianoche,
                        que el sistema todavía no sabe cerrar.</li>
                    <li><strong>Irregular</strong> significa que la secuencia no alterna (una salida sin entrada, o dos
                        entradas seguidas). Por el lector no puede pasar; solo llega de correcciones manuales.</li>
                    <li>No hay tardanzas, horas extra ni ausencias: harían falta un horario oficial y un calendario
                        laboral, y ninguno está definido en el sistema.</li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
