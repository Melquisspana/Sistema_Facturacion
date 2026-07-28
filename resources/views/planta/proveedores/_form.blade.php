@php($p = $proveedor ?? null)

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre *</label>
        <input type="text" name="nombre" value="{{ old('nombre', $p?->nombre) }}" required maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre comercial</label>
        <input type="text" name="nombre_comercial" value="{{ old('nombre_comercial', $p?->nombre_comercial) }}" maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('nombre_comercial') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Contacto</label>
        <input type="text" name="contacto" value="{{ old('contacto', $p?->contacto) }}" maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('contacto') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Teléfono</label>
        <input type="text" name="telefono" value="{{ old('telefono', $p?->telefono) }}" maxlength="30"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('telefono') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Correo</label>
        <input type="email" name="correo" value="{{ old('correo', $p?->correo) }}" maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('correo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Dirección</label>
    <input type="text" name="direccion" value="{{ old('direccion', $p?->direccion) }}" maxlength="255"
           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
    @error('direccion') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">NIT</label>
        <input type="text" name="nit" value="{{ old('nit', $p?->nit) }}" maxlength="20"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Opcional. Dato informativo; no se usa para facturar.</p>
        @error('nit') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">NRC</label>
        <input type="text" name="nrc" value="{{ old('nrc', $p?->nrc) }}" maxlength="20"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('nrc') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-paper-200">
            <input type="hidden" name="activo" value="0">
            <input type="checkbox" name="activo" value="1" @checked(old('activo', $p?->activo ?? true))
                   class="rounded border-gray-300 dark:border-ink-600 dark:bg-ink-800">
            Activo
        </label>
        @error('activo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Observaciones</label>
    <textarea name="observaciones" rows="2"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('observaciones', $p?->observaciones) }}</textarea>
    @error('observaciones') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
</div>
