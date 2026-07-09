@php($p = $producto ?? null)
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Nombre en español *</label>
        <input type="text" name="nombre_es" value="{{ old('nombre_es', $p?->nombre_es) }}" required
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Caja de semilla de marañón horneada">
        @error('nombre_es') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nombre en inglés *</label>
        <input type="text" name="nombre_en" value="{{ old('nombre_en', $p?->nombre_en) }}" required
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Cashew seed baked - 216 units">
        @error('nombre_en') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Código</label>
        <input type="text" name="codigo" value="{{ old('codigo', $p?->codigo) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="opcional">
        @error('codigo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Unidad / empaque</label>
        <input type="text" name="unidad" value="{{ old('unidad', $p?->unidad) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. Bolsa de polipropileno 12X18">
        @error('unidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Unidades por caja *</label>
        <input type="number" name="unidades_por_caja" value="{{ old('unidades_por_caja', $p?->unidades_por_caja) }}" required min="1" step="1"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('unidades_por_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Gramos por unidad *</label>
        <input type="number" name="gramos_por_unidad" value="{{ old('gramos_por_unidad', $p?->gramos_por_unidad) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('gramos_por_unidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Onzas por unidad *</label>
        <input type="number" name="onzas_por_unidad" value="{{ old('onzas_por_unidad', $p?->onzas_por_unidad) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        <p class="mt-1 text-xs text-gray-400">Referencia: gramos × 0.035274</p>
        @error('onzas_por_unidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Precio base por caja ($)</label>
        <input type="number" name="precio_caja" value="{{ old('precio_caja', $p?->precio_caja) }}" min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        <p class="mt-1 text-xs text-gray-400">Referencia opcional: el precio real por cliente se define en su lista de precios</p>
        @error('precio_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">Peso neto caja kg *</label>
        <input type="number" name="peso_neto_caja_kg" value="{{ old('peso_neto_caja_kg', $p?->peso_neto_caja_kg) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('peso_neto_caja_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Peso bruto caja kg *</label>
        <input type="number" name="peso_bruto_caja_kg" value="{{ old('peso_bruto_caja_kg', $p?->peso_bruto_caja_kg) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('peso_bruto_caja_kg') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Peso neto caja lb *</label>
        <input type="number" name="peso_neto_caja_lb" value="{{ old('peso_neto_caja_lb', $p?->peso_neto_caja_lb) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        <p class="mt-1 text-xs text-gray-400">Referencia: kg × 2.2046</p>
        @error('peso_neto_caja_lb') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Peso bruto caja lb *</label>
        <input type="number" name="peso_bruto_caja_lb" value="{{ old('peso_bruto_caja_lb', $p?->peso_bruto_caja_lb) }}" required min="0" step="0.01"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('peso_bruto_caja_lb') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="activo" value="0">
        <input type="checkbox" name="activo" value="1" @checked(old('activo', $p?->activo ?? true)) class="rounded border-gray-300">
        Activo (disponible para nuevas listas de empaque)
    </label>
</div>
