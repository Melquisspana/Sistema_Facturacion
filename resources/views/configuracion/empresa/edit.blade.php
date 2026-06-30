<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Configuración &mdash; Empresa emisora</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <form method="POST" action="{{ route('configuracion.empresa.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="razon_social" value="Razón social *" />
                            <x-text-input id="razon_social" name="razon_social" type="text" class="mt-1 block w-full"
                                :value="old('razon_social', $empresa?->razon_social)" required />
                            <x-input-error :messages="$errors->get('razon_social')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nombre_comercial" value="Nombre comercial" />
                            <x-text-input id="nombre_comercial" name="nombre_comercial" type="text" class="mt-1 block w-full"
                                :value="old('nombre_comercial', $empresa?->nombre_comercial)" />
                            <x-input-error :messages="$errors->get('nombre_comercial')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nit" value="NIT" />
                            <x-text-input id="nit" name="nit" type="text" class="mt-1 block w-full"
                                :value="old('nit', $empresa?->nit)" placeholder="0614-000000-000-0" />
                            <x-input-error :messages="$errors->get('nit')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nrc" value="NRC" />
                            <x-text-input id="nrc" name="nrc" type="text" class="mt-1 block w-full"
                                :value="old('nrc', $empresa?->nrc)" />
                            <x-input-error :messages="$errors->get('nrc')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="actividad_economica_id" value="Actividad económica" />
                            <select id="actividad_economica_id" name="actividad_economica_id"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($actividades as $actividad)
                                    <option value="{{ $actividad->id }}"
                                        @selected(old('actividad_economica_id', $empresa?->actividad_economica_id) == $actividad->id)>
                                        {{ $actividad->codigo }} — {{ $actividad->nombre }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('actividad_economica_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="ambiente" value="Ambiente *" />
                            <select id="ambiente" name="ambiente" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                @foreach (\App\Enums\AmbienteHacienda::cases() as $amb)
                                    <option value="{{ $amb->value }}"
                                        @selected(old('ambiente', $empresa?->ambiente?->value ?? '00') === $amb->value)>
                                        {{ $amb->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('ambiente')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="pais_id" value="País" />
                            <select id="pais_id" name="pais_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— Seleccione —</option>
                                @foreach ($paises as $pais)
                                    <option value="{{ $pais->id }}" @selected(old('pais_id', $empresa?->pais_id) == $pais->id)>{{ $pais->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('pais_id')" class="mt-1" />
                        </div>
                        <x-ubicacion-selects
                            :departamentos="$departamentos"
                            :municipios="$municipios"
                            :distritos="$distritos"
                            :departamento-id="$empresa?->departamento_id"
                            :municipio-id="$empresa?->municipio_id"
                            :distrito-id="$empresa?->distrito_id" />
                        <div>
                            <x-input-label for="telefono" value="Teléfono" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full"
                                :value="old('telefono', $empresa?->telefono)" />
                            <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="correo" value="Correo" />
                            <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full"
                                :value="old('correo', $empresa?->correo)" />
                            <x-input-error :messages="$errors->get('correo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="direccion" value="Dirección" />
                            <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full"
                                :value="old('direccion', $empresa?->direccion)" />
                            <x-input-error :messages="$errors->get('direccion')" class="mt-1" />
                        </div>
                    </div>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $empresa?->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
