@php($p = $presentacion ?? null)

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Producto base *</label>
        <select name="planta_producto_base_id" required
                class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <option value="">— Seleccione —</option>
            @foreach ($productosBase as $base)
                <option value="{{ $base->id }}" @selected(old('planta_producto_base_id', $p?->planta_producto_base_id) == $base->id)>
                    {{ $base->nombre }} ({{ $base->codigo }}){{ $base->activo ? '' : ' — inactivo' }}
                </option>
            @endforeach
        </select>
        @error('planta_producto_base_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Código *</label>
        <input type="text" name="codigo" value="{{ old('codigo', $p?->codigo) }}" required maxlength="30"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. COCO85">
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre *</label>
    <input type="text" name="nombre" value="{{ old('nombre', $p?->nombre) }}" required maxlength="150"
           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
           placeholder="ej. Coco 85 g">
    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Único dentro de su producto base.</p>
    @error('nombre') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Contenido</label>
        <input type="number" name="contenido" value="{{ old('contenido', $p?->contenido) }}" step="0.0001" min="0"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100"
               placeholder="ej. 85">
        @error('contenido') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Unidad</label>
        <select name="unidad_contenido" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <option value="">—</option>
            @foreach ($unidades as $unidad)
                <option value="{{ $unidad }}" @selected(old('unidad_contenido', $p?->unidad_contenido) === $unidad)>{{ $unidad }}</option>
            @endforeach
        </select>
        @error('unidad_contenido') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-paper-200">Unidades por bulto</label>
        <input type="number" name="unidades_por_bulto" value="{{ old('unidades_por_bulto', $p?->unidades_por_bulto) }}" min="1" step="1"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Informativo.</p>
        @error('unidades_por_bulto') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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
