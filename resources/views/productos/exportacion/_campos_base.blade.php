{{-- Nombres y categoría de un producto base. --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label for="base_nombre_es" class="block text-sm font-medium text-gray-700">Nombre en español</label>
        <input id="base_nombre_es" type="text" name="nombre_es" value="{{ old('nombre_es', $base->nombre_es) }}" required maxlength="255"
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Maní dulce" autocomplete="off">
        @error('nombre_es') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="base_nombre_en" class="block text-sm font-medium text-gray-700">Nombre en inglés</label>
        <input id="base_nombre_en" type="text" name="nombre_en" value="{{ old('nombre_en', $base->nombre_en) }}" required maxlength="255"
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Sweet baked peanut" autocomplete="off">
        <p class="mt-1 text-xs text-gray-500">Sin «Caja de» ni «- 144 units»: la lista y la factura lo agregan solas.</p>
        @error('nombre_en') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
<div class="sm:w-1/2">
    <label for="base_categoria" class="block text-sm font-medium text-gray-700">Categoría</label>
    <select id="base_categoria" name="categoria" class="mt-1 w-full rounded-md border-gray-300 text-sm">
        <option value="">— sin categoría —</option>
        @foreach (\App\Enums\CategoriaProductoExportacion::cases() as $categoria)
            <option value="{{ $categoria->value }}" @selected(old('categoria', $base->categoria?->value) === $categoria->value)>{{ $categoria->label() }}</option>
        @endforeach
    </select>
    @error('categoria') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
