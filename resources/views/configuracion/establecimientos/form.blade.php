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
                        {{-- Ubicación administrativa (división 2024): Departamento → Municipio → Distrito.
                             En el emisor el distrito es opcional (compatibilidad con el establecimiento ya configurado). --}}
                        <div class="contents" x-data="{
                                departamentoId: @js((string) old('departamento_id', $establecimiento->departamento_id)),
                                municipioSel: @js((string) old('municipio_2024', $establecimiento->distrito?->municipio)),
                                distritoId: @js((string) old('distrito_id', $establecimiento->distrito_id)),
                                distritos: @js($distritos->map(fn ($d) => ['id' => (string) $d->id, 'nombre' => $d->nombre, 'municipio' => $d->municipio, 'departamento_id' => (string) $d->departamento_id])->values()),
                                get municipiosDelDepto() { return [...new Set(this.distritos.filter(d => d.departamento_id === this.departamentoId).map(d => d.municipio))].sort(); },
                                get distritosFiltrados() { return this.distritos.filter(d => d.departamento_id === this.departamentoId && d.municipio === this.municipioSel); },
                             }">
                            <div>
                                <x-input-label for="departamento_id" value="Departamento *" />
                                <select id="departamento_id" name="departamento_id" x-model="departamentoId"
                                        x-on:change="municipioSel=''; distritoId=''" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    @foreach ($departamentos as $depto)
                                        <option value="{{ $depto->id }}">{{ $depto->nombre }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('departamento_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="municipio_2024" value="Municipio" />
                                <select id="municipio_2024" name="municipio_2024" x-model="municipioSel"
                                        x-on:change="distritoId=''"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    <template x-for="m in municipiosDelDepto" :key="m">
                                        <option :value="m" x-text="m"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="distrito_id" value="Distrito *" />
                                <select id="distrito_id" name="distrito_id" x-model="distritoId" required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">— Seleccione —</option>
                                    <template x-for="d in distritosFiltrados" :key="d.id">
                                        <option :value="d.id" x-text="d.nombre"></option>
                                    </template>
                                </select>
                                <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
                            </div>
                        </div>
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
