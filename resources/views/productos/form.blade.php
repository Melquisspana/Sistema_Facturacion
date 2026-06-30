<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $producto->exists ? 'Editar' : 'Nuevo' }} producto
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <p class="font-medium">Corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ $producto->exists ? route('productos.update', $producto) : route('productos.store') }}"
                      class="space-y-6">
                    @csrf
                    @if ($producto->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="codigo" value="Código interno *" />
                            <x-text-input id="codigo" name="codigo" type="text" maxlength="50" class="mt-1 block w-full"
                                :value="old('codigo', $producto->codigo)" required />
                            <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="codigo_barra" value="Código de barra" />
                            <x-text-input id="codigo_barra" name="codigo_barra" type="text" maxlength="50" class="mt-1 block w-full"
                                :value="old('codigo_barra', $producto->codigo_barra)" placeholder="Opcional" />
                            <x-input-error :messages="$errors->get('codigo_barra')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nombre" value="Nombre *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                :value="old('nombre', $producto->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="tipo_producto" value="Tipo de producto *" />
                            <select id="tipo_producto" name="tipo_producto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposProducto as $valor => $label)
                                    <option value="{{ $valor }}" @selected(old('tipo_producto', $producto->tipo_producto?->value) === $valor)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_producto')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="unidad_medida_id" value="Unidad de medida *" />
                            <select id="unidad_medida_id" name="unidad_medida_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($unidades as $unidad)
                                    <option value="{{ $unidad->id }}" @selected(old('unidad_medida_id', $producto->unidad_medida_id) == $unidad->id)>{{ $unidad->nombre }}{{ $unidad->abreviatura ? ' ('.$unidad->abreviatura.')' : '' }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('unidad_medida_id')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="precio_unitario" value="Precio unitario *" />
                            <x-text-input id="precio_unitario" name="precio_unitario" type="number" step="0.0001" min="0" class="mt-1 block w-full"
                                :value="old('precio_unitario', $producto->precio_unitario)" required />
                            <x-input-error :messages="$errors->get('precio_unitario')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tipo_impuesto" value="Tipo de impuesto *" />
                            <select id="tipo_impuesto" name="tipo_impuesto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposImpuesto as $valor => $label)
                                    <option value="{{ $valor }}" @selected(old('tipo_impuesto', $producto->tipo_impuesto?->value) === $valor)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_impuesto')" class="mt-1" />
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="descripcion" value="Descripción" />
                            <textarea id="descripcion" name="descripcion" rows="2"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('descripcion', $producto->descripcion) }}</textarea>
                            <x-input-error :messages="$errors->get('descripcion')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="observaciones" value="Observaciones" />
                            <textarea id="observaciones" name="observaciones" rows="2"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('observaciones', $producto->observaciones) }}</textarea>
                            <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-5 space-y-3">
                        <label class="inline-flex items-center">
                            <input type="hidden" name="maneja_inventario" value="0">
                            <input type="checkbox" name="maneja_inventario" value="1" class="rounded border-gray-300"
                                @checked(old('maneja_inventario', $producto->maneja_inventario))>
                            <span class="ml-2 text-sm text-gray-700">Maneja inventario (preparado para el módulo futuro; aún no descuenta stock)</span>
                        </label>
                        <br>
                        <label class="inline-flex items-center">
                            <input type="hidden" name="activo" value="0">
                            <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                                @checked(old('activo', $producto->activo ?? true))>
                            <span class="ml-2 text-sm text-gray-700">Activo</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('productos.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
