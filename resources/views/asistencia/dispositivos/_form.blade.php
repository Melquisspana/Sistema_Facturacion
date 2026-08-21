@props(['dispositivo'])
{{-- Campos de un lector. NO hay campo de token: se genera al dar de alta y se
     renueva rotándolo. Si se pudiera escribir, existiría un camino para fijar uno
     débil, repetido o ya conocido por alguien. --}}
<div class="space-y-5">
    <div>
        <label for="nombre" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre</label>
        <input id="nombre" name="nombre" type="text" required maxlength="100"
               value="{{ old('nombre', $dispositivo->nombre) }}"
               placeholder="Entrada principal"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">Dónde está físicamente. Es lo que se lee en los listados.</p>
        @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="codigo" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Código</label>
        <input id="codigo" name="codigo" type="text" required maxlength="50"
               value="{{ old('codigo', $dispositivo->codigo) }}"
               placeholder="lector-entrada"
               class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
            Es lo que el firmware manda en la cabecera <code>X-Dispositivo</code>. Minúsculas, números,
            guiones y guiones bajos.
        </p>
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>
