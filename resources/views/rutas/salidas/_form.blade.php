{{-- Campos compartidos por el alta y la edición de una salida.

     NO incluye `estado` ni `fecha_fin_real`: el estado se mueve con las acciones
     del detalle (iniciar / finalizar / cancelar) y la fecha real la escribe el
     acto de finalizar. Ponerlas acá invitaría a "corregir" el estado a mano. --}}
@php
    $salida = $salida ?? null;
    $elegidos = old('personal', $salida?->participantes->pluck('rutas_personal_id')->all() ?? []);
    $responsableElegido = old('responsable_id', $salida?->participantes->firstWhere('rol', \App\Enums\RolEnSalida::Responsable)?->rutas_personal_id);
@endphp

<div>
    <label for="ruta_id" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Ruta</label>
    <select name="ruta_id" id="ruta_id" required
            class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <option value="">Elegí una ruta…</option>
        @foreach ($rutas as $r)
            <option value="{{ $r->id }}" @selected(old('ruta_id', $salida->ruta_id ?? '') == $r->id)>{{ $r->nombre }}</option>
        @endforeach
    </select>
    @if ($rutas->isEmpty())
        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
            No hay rutas activas. <a href="{{ route('rutas.rutas.create') }}" class="underline">Creá una primero</a>.
        </p>
    @endif
    <x-input-error :messages="$errors->get('ruta_id')" class="mt-1" />
</div>

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
    <div>
        <label for="fecha_inicio" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Fecha de inicio</label>
        <input type="date" name="fecha_inicio" id="fecha_inicio" required
               value="{{ old('fecha_inicio', $salida?->fecha_inicio?->toDateString() ?? now()->toDateString()) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-1" />
    </div>

    <div>
        <label for="fecha_fin_estimada" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
            Regreso estimado <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <input type="date" name="fecha_fin_estimada" id="fecha_fin_estimada"
               value="{{ old('fecha_fin_estimada', $salida?->fecha_fin_estimada?->toDateString() ?? '') }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Una salida puede durar varios días.</p>
        <x-input-error :messages="$errors->get('fecha_fin_estimada')" class="mt-1" />
    </div>
</div>

<div>
    <span class="block text-sm font-medium text-gray-700 dark:text-paper-200">Participantes</span>
    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
        Quiénes van en esta salida. Puede ir más de una persona, y nadie tiene ruta fija.
    </p>

    <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-gray-200 dark:border-ink-600">
        @forelse ($personal as $persona)
            <label class="flex cursor-pointer items-center gap-2.5 border-b border-gray-100 px-3 py-2 last:border-0 hover:bg-gray-50 dark:border-ink-700 dark:hover:bg-ink-700">
                <input type="checkbox" name="personal[]" value="{{ $persona->id }}"
                       @checked(in_array($persona->id, $elegidos))
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800">
                <span class="text-sm text-gray-700 dark:text-paper-200">{{ $persona->nombre }}</span>
                @foreach ($persona->funcionesEnum() as $funcion)
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $funcion->clase() }}">{{ $funcion->label() }}</span>
                @endforeach
            </label>
        @empty
            <p class="px-3 py-4 text-center text-sm text-gray-500 dark:text-paper-400">
                No hay personal de campo activo.
                <a href="{{ route('rutas.personal.create') }}" class="underline">Dá de alta a alguien primero</a>.
            </p>
        @endforelse
    </div>
    <x-input-error :messages="$errors->get('personal')" class="mt-1" />
</div>

<div>
    <label for="responsable_id" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
        Responsable <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
        Quién queda a cargo de este viaje y reúne los documentos al volver. Se elige por salida:
        la misma persona puede ir de acompañante en la siguiente.
    </p>
    <select name="responsable_id" id="responsable_id"
            class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <option value="">Sin responsable designado</option>
        @foreach ($personal as $persona)
            <option value="{{ $persona->id }}" @selected($responsableElegido == $persona->id)>
                {{ $persona->nombre }}@if (! $persona->puedeSerResponsable()) — no declara esa función @endif
            </option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('responsable_id')" class="mt-1" />
</div>

<div>
    <label for="observaciones" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
        Observaciones <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <textarea name="observaciones" id="observaciones" rows="3" maxlength="1000"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('observaciones', $salida->observaciones ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
</div>
