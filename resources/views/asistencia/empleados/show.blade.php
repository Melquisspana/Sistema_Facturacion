{{-- Ficha de una persona: sus datos y TODAS sus asignaciones de ranura.

     Se muestran juntas las VIGENTES y las HISTÓRICAS, y esa es la razón de que
     esta pantalla exista. Una ranura liberada no desaparece: se queda como
     registro de que ese número fue de esta persona durante ese período, y las
     marcaciones de entonces siguen colgando de ella. Esconder el historial haría
     parecer que se borró algo.

     Asignar y liberar viven acá y no en un listado propio de huellas, porque «qué
     ranura es de quién» solo se entiende mirando a la persona completa. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
                {{ $empleado->nombreCompleto() }}
            </h2>
            <div class="flex items-center gap-3">
                <x-asistencia.estado :activo="$empleado->activo"
                                     :accion="route('asistencia.empleados.toggle-activo', $empleado)"
                                     etiquetaActivo="Marca" etiquetaInactivo="No marca" />
                @can('asistencia.gestionar')
                    <a href="{{ route('asistencia.empleados.edit', $empleado) }}"
                       class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:text-paper-200 dark:hover:bg-ink-700">Editar datos</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @php
        $vigentes = $empleado->huellas->where('activo', true);
        $historicas = $empleado->huellas->where('activo', false);
        $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">

            <x-asistencia.avisos />

            {{-- ─────────────────────────────── Datos ─────────────────────────────── --}}
            <div class="{{ $tarjeta }}">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Datos</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Código de planilla:</dt>
                        <dd class="text-gray-800 dark:text-paper-200">{{ $empleado->codigo ?? '—' }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Fecha de ingreso:</dt>
                        <dd class="text-gray-800 dark:text-paper-200">{{ $empleado->fecha_ingreso?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Nombre en el lector:</dt>
                        {{-- La pantalla del ESP32 mide 128x128: no cabe un nombre completo. --}}
                        <dd class="text-gray-800 dark:text-paper-200">{{ $empleado->nombreCorto() }}</dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Cuenta del sistema:</dt>
                        <dd class="text-gray-800 dark:text-paper-200">{{ $empleado->user?->name ?? 'sin cuenta' }}</dd>
                    </div>
                </dl>

                @unless ($empleado->activo)
                    <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-ink-700 dark:text-paper-300">
                        Esta persona está desactivada: el lector rechaza su huella. Su historial de
                        marcaciones se conserva intacto.
                    </p>
                @endunless
            </div>

            {{-- ───────────────── Registrar la huella con el lector ───────────────── --}}
            @include('asistencia.empleados._enrolamiento', [
                'empleado' => $empleado,
                'lectores' => $lectores,
                'ordenViva' => $ordenViva,
                'ordenesRecientes' => $ordenesRecientes,
            ])

            {{-- ──────────────────────── Asignar una ranura ──────────────────────── --}}
            @can('asistencia.gestionar')
                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Anotar una ranura ya grabada</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                        Para huellas que <strong>ya están en el sensor</strong> y solo falta decir de quién son.
                        Si querés grabar una huella nueva, usá «Registrar huella con el lector» de arriba: hace
                        las dos cosas.
                    </p>

                    @if ($lectores->isEmpty())
                        <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            No hay ningún lector activo. Dá de alta uno antes de asignar ranuras.
                        </p>
                    @else
                        <form method="POST" action="{{ route('asistencia.empleados.huellas.store', $empleado) }}"
                              class="mt-4 flex flex-wrap items-end gap-3">
                            @csrf

                            <div>
                                <label for="asistencia_dispositivo_id" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Lector</label>
                                <select id="asistencia_dispositivo_id" name="asistencia_dispositivo_id" required
                                        class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                    @foreach ($lectores as $lector)
                                        <option value="{{ $lector->id }}" @selected(old('asistencia_dispositivo_id') == $lector->id)>
                                            {{ $lector->nombre }} ({{ $lector->codigo }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('asistencia_dispositivo_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="fingerprint_id" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Número de ranura</label>
                                <input id="fingerprint_id" name="fingerprint_id" type="number" min="0" max="65535" required
                                       value="{{ old('fingerprint_id') }}"
                                       class="mt-1 w-40 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                @error('fingerprint_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Asignar</button>
                        </form>

                        <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                            Es el número que el sensor devuelve al reconocer el dedo. Si esa ranura ya tiene
                            una asignación vigente, el sistema lo dice y no cambia nada.
                        </p>
                    @endif
                </div>
            @endcan

            {{-- ────────────────────── Asignaciones vigentes ────────────────────── --}}
            <div class="{{ $tarjeta }}">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Ranuras vigentes</h3>

                @if ($vigentes->isEmpty())
                    <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                        Sin ranuras asignadas: esta persona <strong>no puede marcar todavía</strong>.
                    </p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                                    <th class="py-2 pr-4">Lector</th>
                                    <th class="py-2 pr-4">Ranura</th>
                                    <th class="py-2 pr-4">Asignada</th>
                                    <th class="py-2 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @foreach ($vigentes as $huella)
                                    <tr>
                                        <td class="py-3 pr-4 text-gray-800 dark:text-paper-200">
                                            {{ $huella->dispositivo?->nombre ?? '—' }}
                                            <span class="text-xs text-gray-400 dark:text-paper-500">({{ $huella->dispositivo?->codigo }})</span>
                                        </td>
                                        <td class="py-3 pr-4 font-medium tabular-nums text-gray-800 dark:text-paper-100">{{ $huella->fingerprint_id }}</td>
                                        <td class="py-3 pr-4 text-gray-600 dark:text-paper-300">{{ $huella->created_at?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="py-3 text-right">
                                            @can('asistencia.gestionar')
                                                <form method="POST" action="{{ route('asistencia.huellas.liberar', $huella) }}" class="inline"
                                                      onsubmit="return confirm('Liberar la ranura {{ $huella->fingerprint_id }}. La asignación queda en el historial y las marcaciones ya registradas no se tocan. Acordate de borrar la plantilla en el sensor antes de asignarla a otra persona. ¿Continuar?');">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="text-sm text-red-600 hover:underline dark:text-red-400">Liberar</button>
                                                </form>
                                            @else
                                                <span class="text-xs text-gray-400 dark:text-paper-500">—</span>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ────────────────────── Asignaciones históricas ────────────────────── --}}
            @if ($historicas->isNotEmpty())
                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Ranuras que tuvo antes</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
                        Estas asignaciones ya no están vigentes y su número puede ser de otra persona hoy.
                        <strong>No se borran ni se reasignan</strong>: las marcaciones de aquel período siguen
                        apuntando acá.
                    </p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                                    <th class="py-2 pr-4">Lector</th>
                                    <th class="py-2 pr-4">Ranura</th>
                                    <th class="py-2 pr-4">Asignada</th>
                                    <th class="py-2 pr-4">Liberada</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-500 dark:divide-ink-700 dark:text-paper-400">
                                @foreach ($historicas as $huella)
                                    <tr>
                                        <td class="py-3 pr-4">{{ $huella->dispositivo?->nombre ?? '—' }}</td>
                                        <td class="py-3 pr-4 tabular-nums">{{ $huella->fingerprint_id }}</td>
                                        <td class="py-3 pr-4">{{ $huella->created_at?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="py-3 pr-4">{{ $huella->liberada_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ────────────────────── Últimas marcaciones ────────────────────── --}}
            <div class="{{ $tarjeta }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Últimas marcaciones</h3>
                    <a href="{{ route('asistencia.marcaciones.index', ['empleado_id' => $empleado->id]) }}"
                       class="text-sm text-indigo-600 hover:underline dark:text-indigo-300">Ver historial completo →</a>
                </div>

                @if ($ultimas->isEmpty())
                    <p class="mt-3 text-sm text-gray-500 dark:text-paper-400">
                        Esta persona todavía no ha marcado ninguna vez.
                    </p>
                @else
                    {{-- Solo las últimas: la ficha responde «¿el lector la está
                         registrando?». Consultar el historial con filtros es otra
                         pantalla, y duplicarla acá sería mantener dos tablas. --}}
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-400">
                                    <th class="py-2 pr-4">Fecha</th>
                                    <th class="py-2 pr-4">Hora</th>
                                    <th class="py-2 pr-4">Tipo</th>
                                    <th class="py-2 pr-4">Origen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                @foreach ($ultimas as $marcacion)
                                    <tr>
                                        <td class="py-2.5 pr-4 tabular-nums text-gray-800 dark:text-paper-200">{{ $marcacion->fecha_local->format('d/m/Y') }}</td>
                                        <td class="py-2.5 pr-4 tabular-nums font-medium text-gray-800 dark:text-paper-100">
                                            {{ $marcacion->marcado_at->copy()->setTimezone($zona)->format('H:i:s') }}
                                        </td>
                                        <td class="py-2.5 pr-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' => $marcacion->tipo === \App\Enums\Asistencia\TipoMarcacion::Entrada,
                                                'bg-gray-100 text-gray-700 dark:bg-ink-700 dark:text-paper-300' => $marcacion->tipo === \App\Enums\Asistencia\TipoMarcacion::Salida,
                                            ])>{{ $marcacion->tipo->label() }}</span>
                                        </td>
                                        <td class="py-2.5 pr-4"><x-asistencia.origen-marcacion :marcacion="$marcacion" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                        Hora oficial del servidor ({{ $zona }}). Las marcaciones no se editan ni se borran.
                    </p>
                @endif
            </div>

            <a href="{{ route('asistencia.empleados.index') }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-400">← Volver a empleados</a>
        </div>
    </div>
</x-app-layout>
