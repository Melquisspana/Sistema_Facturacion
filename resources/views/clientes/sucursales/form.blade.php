<x-app-layout>
    <x-slot name="header">
        {{-- El nombre FISCAL del cliente, no el comercial: es el que identifica al
             receptor del documento y evita agregarle la sala al cliente equivocado
             cuando dos razones sociales comparten nombre de fantasía. --}}
        <h2 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">
            {{ $sucursal->exists
                ? 'Editar sala — '.$cliente->nombre
                : 'Nueva sala para '.$cliente->nombre }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST"
                      action="{{ $sucursal->exists ? route('clientes.sucursales.update', [$cliente, $sucursal]) : route('clientes.sucursales.store', $cliente) }}"
                      class="space-y-6">
                    @csrf
                    @if ($sucursal->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="nombre" value="Sala / nombre comercial *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                          :value="old('nombre', $sucursal->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="codigo" value="Código interno (opcional)" />
                            <x-text-input id="codigo" name="codigo" type="text" class="mt-1 block w-full"
                                          :value="old('codigo', $sucursal->codigo)" />
                            <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="direccion" value="Dirección" />
                            <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                          :value="old('direccion', $sucursal->direccion)" />
                            <x-input-error :messages="$errors->get('direccion')" class="mt-1" />
                        </div>

                        {{-- Ubicación administrativa: Departamento → Municipio 2024 (CAT-013)
                             → Distrito (CAT-008), con el MISMO componente que usan empresa,
                             establecimientos y clientes.
                             Antes este formulario tenía su propia cascada: el distrito
                             listaba TODO el departamento agrupado en <optgroup> por municipio
                             2024, y el municipio fiscal era un select aparte filtrado solo por
                             departamento. Los <optgroup> parecían indicar el municipio elegido
                             pero eran decorativos, así que se podía guardar «Cabañas Este» con
                             el distrito «Ilobasco» (de Cabañas Oeste) — el par que Hacienda
                             rechaza. Ahora el distrito se filtra POR el municipio elegido. --}}
                        <x-ubicacion-selects
                            :departamentos="$departamentos"
                            :municipios="$municipios"
                            :distritos="$distritos"
                            :departamento-id="$sucursal->departamento_id"
                            :municipio-id="$sucursal->municipio_id"
                            :distrito-id="$sucursal->distrito_id"
                            :distrito-requerido="true"
                            :departamento-requerido="true" />

                        <div>
                            <x-input-label for="telefono" value="Teléfono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                          :value="old('telefono', $sucursal->telefono)" />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="correo" value="Correo" />
                            <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                          :value="old('correo', $sucursal->correo)" />
                            <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="requiere_orden_compra" value="Requiere orden de compra" />
                            <select id="requiere_orden_compra" name="requiere_orden_compra"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm">
                                @php $ocActual = old('requiere_orden_compra', $sucursal->requiere_orden_compra); @endphp
                                <option value="" @selected(is_null($ocActual))>Heredar del cliente</option>
                                <option value="1" @selected($ocActual === true || $ocActual === '1')>Sí</option>
                                <option value="0" @selected($ocActual === false || $ocActual === '0')>No</option>
                            </select>
                            <x-input-error :messages="$errors->get('requiere_orden_compra')" class="mt-1" />
                        </div>

                        <div>
                            <x-input-label for="activo" value="Estado" />
                            <select id="activo" name="activo" class="mt-1 block w-full rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm">
                                <option value="1" @selected((string) old('activo', (int) $sucursal->activo) === '1')>Activa</option>
                                <option value="0" @selected((string) old('activo', (int) $sucursal->activo) === '0')>Inactiva</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <x-input-label for="observaciones" value="Observaciones" />
                            <textarea id="observaciones" name="observaciones" rows="2"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100 shadow-sm">{{ old('observaciones', $sucursal->observaciones) }}</textarea>
                            <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>{{ $sucursal->exists ? 'Guardar cambios' : 'Crear sala' }}</x-primary-button>
                        <a href="{{ route('clientes.show', $cliente) }}" class="text-sm text-gray-500 dark:text-paper-300 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
