@php
    $sinSincronizar = $lectores->reject->tieneIndiceSincronizado();
@endphp
<div class="{{ $tarjeta }}">
    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Guardar huella</h3>
    @if ($ordenViva)
        <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-500/30 dark:bg-blue-500/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $ordenViva->estado->clases() }}">{{ $ordenViva->estado->label() }}</span>
                    <p class="mt-2 text-sm text-blue-900 dark:text-blue-200">{{ $ordenViva->estado->explicacion() }}</p>
                    <p class="mt-2 text-xs text-blue-800 dark:text-blue-300">Lector: <strong>{{ $ordenViva->dispositivo?->nombre }}</strong> · expira en {{ $ordenViva->segundosParaExpirar() }} s</p>
                </div>
                @can('asistencia.gestionar')
                    <form method="POST" action="{{ route('asistencia.empleados.enrolamiento.destroy', [$empleado, $ordenViva]) }}" class="shrink-0"
                          onsubmit="return confirm('Si el lector ya está capturando, cancelar puede dejar una huella guardada sin dueño. Esperá a que termine. ¿Cancelar de todos modos?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-300 dark:hover:bg-red-500/10">Cancelar</button>
                    </form>
                @endcan
            </div>
            <p role="alert" class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">No canceles mientras el lector pide el dedo: cancelar durante la captura puede dejar una huella guardada en el lector sin dueño.</p>
        </div>
        <script>setTimeout(function () { window.location.reload(); }, 3000);</script>
    @else
        <p class="mt-2 text-sm text-gray-600 dark:text-paper-300">El lector le pedirá a {{ $empleado->nombreCorto() }} que coloque el dedo dos veces. El sistema elige dónde guardarlo.</p>
        @if (! $empleado->activo)
            <p class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-ink-700 dark:text-paper-300">Esta persona está desactivada. Reactivala antes de guardarle una huella.</p>
        @elseif ($lectores->isEmpty())
            <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">No hay ningún lector activo. Pedí que lo configuren antes de guardar huellas.</p>
        @else
            @can('asistencia.gestionar')
                <form id="guardar-huella" method="POST" action="{{ route('asistencia.empleados.enrolamiento.store', $empleado) }}" class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="enrolamiento_lector" class="block text-xs font-medium text-gray-500 dark:text-paper-400">Lector</label>
                        <select id="enrolamiento_lector" name="asistencia_dispositivo_id" required class="mt-1 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                            @foreach ($lectores as $lector)
                                <option value="{{ $lector->id }}" @selected(old('asistencia_dispositivo_id') == $lector->id)>{{ $lector->nombre }} @unless ($lector->tieneIndiceSincronizado()) — sin sincronizar @endunless</option>
                            @endforeach
                        </select>
                        @error('asistencia_dispositivo_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar huella</button>
                </form>
                @if ($sinSincronizar->isNotEmpty())
                    <p class="mt-3 text-xs text-gray-500 dark:text-paper-400">{{ $sinSincronizar->count() }} lector(es) sin sincronizar: encendelos y esperá a que se comuniquen antes de guardar la huella.</p>
                @endif
            @endcan
        @endif
    @endif
    <p class="mt-4 text-xs text-gray-400 dark:text-paper-500">La huella se guarda dentro del lector. Este servidor solo registra a quién pertenece.</p>
</div>
