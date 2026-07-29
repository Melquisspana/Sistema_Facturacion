@php($p = $producto ?? null)

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Código *</label>
        <input type="text" name="codigo" value="{{ old('codigo', $p?->codigo) }}" required maxlength="30"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. COCO">
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre *</label>
        <input type="text" name="nombre" value="{{ old('nombre', $p?->nombre) }}" required maxlength="150"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. Dulce de coco">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
            La identidad del dulce, sin el formato: el peso y el empaque van en sus presentaciones.
        </p>
        @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Descripción</label>
    <textarea name="descripcion" rows="2"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('descripcion', $p?->descripcion) }}</textarea>
    @error('descripcion') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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
