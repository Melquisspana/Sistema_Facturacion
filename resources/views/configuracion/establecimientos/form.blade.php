<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Configuración &mdash; {{ $establecimiento->exists ? 'Editar' : 'Nuevo' }} establecimiento
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <form method="POST"
                      action="{{ $establecimiento->exists ? route('configuracion.establecimientos.update', $establecimiento) : route('configuracion.establecimientos.store') }}"
                      class="space-y-6">
                    @csrf
                    @if ($establecimiento->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="empresa_id" value="Empresa *" />
                            <select id="empresa_id" name="empresa_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($empresas as $emp)
                                    <option value="{{ $emp->id }}" @selected(old('empresa_id', $establecimiento->empresa_id) == $emp->id)>{{ $emp->razon_social }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('empresa_id')" class="mt-1" />
                            @if ($empresas->isEmpty())
                                <p class="text-xs text-amber-600 mt-1">Primero registra la empresa emisora.</p>
                            @endif
                        </div>
                        <div>
                            <x-input-label for="codigo" value="Código de establecimiento * (ej. M001)" />
                            <x-text-input id="codigo" name="codigo" type="text" maxlength="4" class="mt-1 block w-full"
                                :value="old('codigo', $establecimiento->codigo)" required />
                            <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nombre" value="Nombre *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                :value="old('nombre', $establecimiento->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tipo_establecimiento" value="Tipo de establecimiento" />
                            <select id="tipo_establecimiento" name="tipo_establecimiento" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposEstablecimiento as $codigo => $label)
                                    <option value="{{ $codigo }}" @selected(old('tipo_establecimiento', $establecimiento->tipo_establecimiento?->value) === $codigo)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_establecimiento')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="pais_id" value="País" />
                            <select id="pais_id" name="pais_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($paises as $pais)
                                    <option value="{{ $pais->id }}" @selected(old('pais_id', $establecimiento->pais_id) == $pais->id)>{{ $pais->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Ubicación administrativa (división 2024): Departamento → Municipio
                             2024 (CAT-013) → Distrito (CAT-008), con el MISMO componente que
                             usan empresa, clientes y salas.
                             Antes el select de municipio se llamaba `municipio_2024` y enviaba
                             el NOMBRE de la agrupación: servía para filtrar los distritos en
                             pantalla pero NUNCA guardaba `municipio_id`, así que el emisor podía
                             quedar sin municipio fiscal (y el JSON con `municipio: ""`). Ahora
                             se guarda el municipio real y el distrito se filtra por él. --}}
                        <x-ubicacion-selects
                            :departamentos="$departamentos"
                            :municipios="$municipios"
                            :distritos="$distritos"
                            :departamento-id="$establecimiento->departamento_id"
                            :municipio-id="$establecimiento->municipio_id"
                            :distrito-id="$establecimiento->distrito_id"
                            :distrito-requerido="true"
                            :departamento-requerido="true" />
                        <div>
                            <x-input-label for="telefono" value="Teléfono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                :value="old('telefono', $establecimiento->telefono)" />
                        </div>
                        <div>
                            <x-input-label for="correo" value="Correo" />
                            <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                :value="old('correo', $establecimiento->correo)" />
                            <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="direccion" value="Dirección" />
                            <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                :value="old('direccion', $establecimiento->direccion)" />
                        </div>
                    </div>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $establecimiento->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('configuracion.establecimientos.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
