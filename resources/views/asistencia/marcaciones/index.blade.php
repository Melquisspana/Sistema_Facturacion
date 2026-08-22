{{-- Historial de marcaciones. SOLO CONSULTA.

     No hay ni un botón de editar o borrar, y no falta: una marcación es un hecho
     ya ocurrido. Cuando exista la corrección manual será una fila NUEVA con
     `origen = 'manual'`, nunca una edición encima del hecho — y por eso esta
     pantalla ya sabe distinguir los dos orígenes.

     LAS FECHAS SON DÍAS LOCALES. El filtro compara contra `fecha_local`, no
     contra el instante en UTC: en El Salvador (UTC−6) una marcación de las 19:30
     del día 5 se guarda como 01:30 UTC del día 6, y filtrar por el instante
     desplazaría el turno de la tarde entero al día siguiente.

     La cabecera muestra CONTEOS. No hay horas trabajadas ni tardanzas: necesitan
     horarios y los horarios no existen todavía. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Historial de marcaciones</h2>
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
            <form method="GET" action="{{ route('asistencia.marcaciones.index') }}"
                  class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="lg:col-span-2">
                        <label for="empleado_id" class="{{ $etiqueta }}">Empleado</label>
                        <select id="empleado_id" name="empleado_id" class="{{ $campo }}">
                            <option value="">Todos</option>
                            {{-- Incluye a los INACTIVOS: el historial de quien ya no
                                 trabaja acá es justamente lo que se viene a buscar. --}}
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
                        <label for="tipo" class="{{ $etiqueta }}">Tipo</label>
                        <select id="tipo" name="tipo" class="{{ $campo }}">
                            <option value="">Entradas y salidas</option>
                            @foreach ($tipos as $unTipo)
                                <option value="{{ $unTipo->value }}" @selected($filtro->tipo === $unTipo)>{{ $unTipo->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                    @if ($filtro->tieneFiltros())
                        <a href="{{ route('asistencia.marcaciones.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Limpiar</a>
                    @endif
                    <span class="ml-auto text-xs text-gray-400 dark:text-paper-500">
                        Las fechas son días completos en {{ $zona }}, ambos extremos incluidos.
                    </span>
                </div>
            </form>

            {{-- ─────────────────────────────── Resumen ─────────────────────────────── --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'Marcaciones' => $resumen['total'],
                    'Entradas' => $resumen['entradas'],
                    'Salidas' => $resumen['salidas'],
                    'Personas' => $resumen['personas'],
                ] as $rotulo => $valor)
                    <div class="{{ $tarjeta }}">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-paper-400">{{ $rotulo }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-paper-100">{{ $valor }}</p>
                    </div>
                @endforeach
            </div>

            <p class="text-sm text-gray-500 dark:text-paper-400">
                {{ $filtro->descripcion([
                    'empleado' => $empleados->firstWhere('id', $filtro->empleadoId)?->nombreCompleto(),
                    'dispositivo' => $lectores->firstWhere('id', $filtro->dispositivoId)?->nombre,
                ]) }}
                @if ($resumen['dias'] > 0)
                    &middot; {{ $resumen['dias'] }} día(s) con actividad
                @endif
            </p>

            {{-- ─────────────────────────────── Listado ─────────────────────────────── --}}
            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-400">
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3">Hora</th>
                                <th class="px-4 py-3">Empleado</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Origen</th>
                                <th class="px-4 py-3">Ranura</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($marcaciones as $marcacion)
                                @php $tiempo = $marcacion->marcado_at->copy()->setTimezone($zona); @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700">
                                    <td class="px-4 py-3 tabular-nums text-gray-800 dark:text-paper-200">{{ $marcacion->fecha_local->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3 tabular-nums font-medium text-gray-800 dark:text-paper-100"
                                        title="{{ $marcacion->marcado_at->format('Y-m-d H:i:s') }} UTC">
                                        {{ $tiempo->format('H:i:s') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($marcacion->empleado)
                                            <a href="{{ route('asistencia.empleados.show', $marcacion->empleado) }}"
                                               class="text-gray-800 hover:underline dark:text-paper-200">{{ $marcacion->empleado->nombreCompleto() }}</a>
                                            @unless ($marcacion->empleado->activo)
                                                <span class="ml-1 text-xs text-gray-400 dark:text-paper-500">(inactivo)</span>
                                            @endunless
                                        @else
                                            <span class="text-gray-400 dark:text-paper-500">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' => $marcacion->tipo === \App\Enums\Asistencia\TipoMarcacion::Entrada,
                                            'bg-gray-100 text-gray-700 dark:bg-ink-700 dark:text-paper-300' => $marcacion->tipo === \App\Enums\Asistencia\TipoMarcacion::Salida,
                                        ])>{{ $marcacion->tipo->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3"><x-asistencia.origen-marcacion :marcacion="$marcacion" /></td>
                                    <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-paper-300">
                                        @if ($marcacion->huella)
                                            {{ $marcacion->huella->fingerprint_id }}
                                            @unless ($marcacion->huella->activo)
                                                {{-- La asignación se liberó después de esta marcación. La fila
                                                     histórica no se borra ni se reasigna: sigue siendo la que
                                                     identificó a esta persona ese día. --}}
                                                <span class="block text-xs text-gray-400 dark:text-paper-500">asignación liberada</span>
                                            @endunless
                                        @else
                                            <span class="text-gray-400 dark:text-paper-500">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-gray-400 dark:text-paper-500">
                                        @if ($filtro->tieneFiltros())
                                            Ninguna marcación coincide con estos filtros.
                                        @else
                                            Todavía no hay marcaciones registradas. Aparecen solas cuando alguien
                                            usa el lector.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($marcaciones->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3 dark:border-ink-700">{{ $marcaciones->links() }}</div>
                @endif
            </div>

            <p class="text-xs text-gray-400 dark:text-paper-500">
                Las marcaciones no se editan ni se borran: son un registro de solo añadir. La hora que se
                muestra es la que puso el servidor ({{ $zona }}); el reloj del lector nunca es fuente de verdad.
            </p>
        </div>
    </div>
</x-app-layout>
