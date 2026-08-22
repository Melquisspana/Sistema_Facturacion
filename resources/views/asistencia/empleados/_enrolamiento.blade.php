@props(['empleado', 'lectores', 'ordenViva', 'ordenesRecientes'])
{{-- REGISTRAR LA HUELLA CON EL LECTOR.

     Esta tarjeta pide al ESP32 que grabe la huella; no la graba ella. El servidor
     pone una orden en el buzón del lector, el lector la recoge cuando sondea, y
     el AS608 es quien confirma. La asignación solo nace con esa confirmación.

     LA RANURA NO SE ESCRIBE. El sistema elige la menor libre cruzando lo que sabe
     la base —asignadas y reservadas— con lo que reportó el sensor —plantillas
     físicas—. El campo manual vive tras «opciones avanzadas» y existe solo para
     sensores con plantillas heredadas.

     Mientras hay una orden viva la página se refresca sola cada 3 s: es lo único
     que puede hacer sin una conexión permanente, y el enrolamiento dura menos de
     un minuto. --}}
@php
    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $sinSincronizar = $lectores->reject->tieneIndiceSincronizado();
@endphp

<div class="{{ $tarjeta }}">
    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Registrar huella con el lector</h3>

    @if ($ordenViva)
        {{-- ─────────────────────── Hay un registro en curso ─────────────────────── --}}
        <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-500/30 dark:bg-blue-500/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $ordenViva->estado->clases() }}">
                        {{ $ordenViva->estado->label() }}
                    </span>
                    <p class="mt-2 text-sm text-blue-900 dark:text-blue-200">{{ $ordenViva->estado->explicacion() }}</p>
                    @if ($ordenViva->detalle)
                        <p class="mt-1 text-sm text-blue-800 dark:text-blue-300">{{ $ordenViva->detalle }}</p>
                    @endif
                    <p class="mt-2 text-xs text-blue-800 dark:text-blue-300">
                        Lector: <strong>{{ $ordenViva->dispositivo?->nombre }}</strong>
                        &middot; Ranura <strong>{{ $ordenViva->ranura_reservada }}</strong>
                        @if ($ordenViva->ranura_manual)
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">manual</span>
                        @endif
                        &middot; expira en {{ $ordenViva->segundosParaExpirar() }} s
                    </p>
                </div>

                @can('asistencia.gestionar')
                    <form method="POST" action="{{ route('asistencia.empleados.enrolamiento.destroy', [$empleado, $ordenViva]) }}"
                          class="shrink-0"
                          onsubmit="return confirm('Cancelar el registro. Si el sensor todavía no grabó nada, no queda ninguna huella. ¿Continuar?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-300 dark:hover:bg-red-500/10">
                            Cancelar
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Sin conexión permanente, recargar es lo único honesto. Solo mientras
             la orden vive: en cuanto termina, la página deja de moverse sola. --}}
        <script>setTimeout(function () { window.location.reload(); }, 3000);</script>
    @else
        {{-- ───────────────────────── Iniciar un registro ───────────────────────── --}}
        <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">
            El lector le pedirá a {{ $empleado->nombreCorto() }} que coloque el dedo dos veces. La ranura
            del sensor la elige el sistema: <strong>no hace falta escribir ningún número</strong>.
        </p>

        @if (! $empleado->activo)
            <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-ink-700 dark:text-paper-300">
                Esta persona está desactivada. Reactivala antes de registrarle una huella: no podría marcar con ella.
            </p>
        @elseif ($lectores->isEmpty())
            <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                No hay ningún lector activo. Dá de alta uno antes de registrar huellas.
            </p>
        @else
            @can('asistencia.gestionar')
                <form method="POST" action="{{ route('asistencia.empleados.enrolamiento.store', $empleado) }}" class="mt-4">
                    @csrf

                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="enrolamiento_lector" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Lector</label>
                            <select id="enrolamiento_lector" name="asistencia_dispositivo_id" required
                                    class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                                @foreach ($lectores as $lector)
                                    <option value="{{ $lector->id }}" @selected(old('asistencia_dispositivo_id') == $lector->id)>
                                        {{ $lector->nombre }} @unless ($lector->tieneIndiceSincronizado()) — sin sincronizar @endunless
                                    </option>
                                @endforeach
                            </select>
                            @error('asistencia_dispositivo_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            Registrar huella
                        </button>
                    </div>

                    {{-- ESCAPE MANUAL. Cerrado por defecto y con su advertencia: no es
                         el camino normal y no debe parecerlo. --}}
                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs text-gray-500 hover:underline dark:text-paper-400">Opciones avanzadas</summary>

                        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                <strong>Solo para recuperación.</strong> Normalmente el sistema elige la ranura solo.
                                Escribí un número únicamente si el sensor ya traía plantillas de antes y sabés
                                exactamente qué hueco usar. Se comprueba igual contra las asignaciones, las reservas
                                y el índice del sensor: si está ocupada, no se usa.
                            </p>

                            <div class="mt-3">
                                <label for="enrolamiento_ranura" class="block text-xs font-medium text-amber-900 dark:text-amber-200">Ranura del sensor</label>
                                <input id="enrolamiento_ranura" name="ranura" type="number" min="0" max="65535"
                                       value="{{ old('ranura') }}" placeholder="automática"
                                       class="mt-1 w-40 rounded-md border-amber-300 text-sm dark:border-amber-500/40 dark:bg-ink-900 dark:text-paper-100">
                                @error('ranura') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </details>
                </form>

                @if ($sinSincronizar->isNotEmpty())
                    <p class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-ink-700 dark:text-paper-300">
                        {{ $sinSincronizar->count() }} lector(es) todavía no han reportado sus ranuras. Hasta que lo
                        hagan, el sistema no puede elegir automáticamente en ellos: encendelos y esperá a que se
                        comuniquen.
                    </p>
                @endif
            @endcan
        @endif
    @endif

    {{-- ───────────────────── Qué pasó en los últimos intentos ───────────────────── --}}
    @if ($ordenesRecientes->isNotEmpty())
        <div class="mt-5 border-t border-gray-100 pt-4 dark:border-ink-700">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-paper-500">Intentos recientes</h4>
            <ul class="mt-2 space-y-1.5">
                @foreach ($ordenesRecientes as $orden)
                    <li class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="inline-flex rounded-full px-2 py-0.5 font-medium {{ $orden->estado->clases() }}">{{ $orden->estado->label() }}</span>
                        <span class="text-gray-500 dark:text-paper-400">
                            {{ $orden->finalizada_at?->diffForHumans() ?? $orden->created_at?->diffForHumans() }}
                            &middot; ranura {{ $orden->ranura_reservada }}
                            @if ($orden->dispositivo) &middot; {{ $orden->dispositivo->nombre }} @endif
                        </span>
                        @if ($orden->detalle)
                            <span class="w-full text-gray-500 dark:text-paper-400">{{ $orden->detalle }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="mt-4 text-xs text-gray-400 dark:text-paper-500">
        La plantilla de la huella se guarda <strong>dentro del sensor</strong>, nunca en este servidor. Acá
        solo queda a quién corresponde cada ranura.
    </p>
</div>
