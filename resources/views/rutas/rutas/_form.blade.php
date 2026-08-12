{{-- Campos compartidos por el alta y la edición de una ruta. --}}
@php $ruta = $ruta ?? null; @endphp

<div>
    <label for="nombre" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre</label>
    <input type="text" name="nombre" id="nombre" required maxlength="120"
           value="{{ old('nombre', $ruta->nombre ?? '') }}"
           placeholder="San Miguel, Santa Ana, Sonsonate…"
           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
    <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
</div>

<div>
    <label for="frecuencia_objetivo_dias" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
        Frecuencia objetivo <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <div class="mt-1 flex items-center gap-2">
        <span class="text-sm text-gray-500 dark:text-paper-400">cada</span>
        <input type="number" name="frecuencia_objetivo_dias" id="frecuencia_objetivo_dias" min="1" max="365"
               value="{{ old('frecuencia_objetivo_dias', $ruta->frecuencia_objetivo_dias ?? '') }}"
               class="w-24 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <span class="text-sm text-gray-500 dark:text-paper-400">días</span>
    </div>
    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
        Cada cuánto se pretende visitar esta ruta. Es una referencia: el sistema no agenda ni bloquea nada con este dato.
    </p>
    <x-input-error :messages="$errors->get('frecuencia_objetivo_dias')" class="mt-1" />
</div>

<div>
    <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="activa" value="1" @checked(old('activa', $ruta->activa ?? true))
               class="rounded border-gray-300 text-indigo-600 dark:border-ink-600 dark:bg-ink-800">
        <span class="text-sm text-gray-700 dark:text-paper-200">Ruta activa</span>
    </label>
    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
        Una ruta inactiva no se ofrece al crear salidas nuevas, pero conserva sus salas y su historial.
    </p>
</div>
