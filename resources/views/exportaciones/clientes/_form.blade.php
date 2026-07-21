@php($c = $cliente ?? null)
@if ($c?->cliente)
    <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-xs text-gray-500">
        El nombre legal y la dirección fiscal de <strong>{{ $c->cliente->nombre }}</strong> vienen del
        <a href="{{ route('clientes.edit', $c->cliente) }}" class="text-indigo-600 hover:underline">Cliente DTE vinculado</a>
        y no se editan acá. Los campos de abajo son datos propios de este perfil de exportación.
    </div>
@endif

<div>
    <label class="block text-sm font-medium text-gray-700">Nombre operativo *</label>
    <input type="text" name="nombre" value="{{ old('nombre', $c?->nombre) }}" required
           class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. CAROLINAS WHOLESALE LLC">
    <p class="mt-1 text-xs text-gray-400">Alias interno para identificar este perfil. NO es el nombre legal (ese lo define el Cliente DTE vinculado).</p>
    @error('nombre') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div>
    <label class="block text-sm font-medium text-gray-700">Dirección de entrega/bodega</label>
    <input type="text" name="direccion" value="{{ old('direccion', $c?->direccion) }}"
           class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="ej. 11235 SOMERSET, BELTSVILLE, MD 20705 EEUU">
    <p class="mt-1 text-xs text-gray-400">Opcional. Dejala vacía si es la misma dirección fiscal del Cliente DTE vinculado — no la repitas acá.</p>
    @error('direccion') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">FDA reg. number</label>
        <input type="text" name="fda_reg_number" value="{{ old('fda_reg_number', $c?->fda_reg_number) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm">
        @error('fda_reg_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Contacto</label>
        <input type="text" name="contacto" value="{{ old('contacto', $c?->contacto) }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="nombre, teléfono o correo (opcional)">
        @error('contacto') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<div>
    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
        <input type="hidden" name="activo" value="0">
        <input type="checkbox" name="activo" value="1" @checked(old('activo', $c?->activo ?? true)) class="rounded border-gray-300">
        Activo (disponible para nuevas listas de empaque)
    </label>
</div>
