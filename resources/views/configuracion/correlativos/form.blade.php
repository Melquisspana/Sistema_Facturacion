<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Configuración &mdash; {{ $correlativo->exists ? 'Editar' : 'Nuevo' }} correlativo
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <form method="POST"
                      action="{{ $correlativo->exists ? route('configuracion.correlativos.update', $correlativo) : route('configuracion.correlativos.store') }}"
                      class="space-y-6">
                    @csrf
                    @if ($correlativo->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="tipo_dte" value="Tipo de DTE *" />
                            <select id="tipo_dte" name="tipo_dte" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($tiposDte as $tipo)
                                    <option value="{{ $tipo->value }}" @selected(old('tipo_dte', $correlativo->tipo_dte?->value) === $tipo->value)>
                                        {{ $tipo->value }} — {{ $tipo->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo_dte')" class="mt-1" />
                            <x-input-error :messages="$errors->get('tipo_dte_combo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="ambiente" value="Ambiente *" />
                            <select id="ambiente" name="ambiente" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                @foreach ($ambientes as $amb)
                                    <option value="{{ $amb->value }}" @selected(old('ambiente', $correlativo->ambiente?->value ?? '00') === $amb->value)>{{ $amb->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('ambiente')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="establecimiento_id" value="Establecimiento *" />
                            <select id="establecimiento_id" name="establecimiento_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($establecimientos as $est)
                                    <option value="{{ $est->id }}" @selected(old('establecimiento_id', $correlativo->establecimiento_id) == $est->id)>{{ $est->codigo }} — {{ $est->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('establecimiento_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="punto_venta_id" value="Punto de venta" />
                            <select id="punto_venta_id" name="punto_venta_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">— (ninguno) —</option>
                                @foreach ($puntosVenta as $pv)
                                    <option value="{{ $pv->id }}" @selected(old('punto_venta_id', $correlativo->punto_venta_id) == $pv->id)>{{ $pv->establecimiento?->codigo }}/{{ $pv->codigo }} — {{ $pv->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('punto_venta_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="serie" value="Serie / prefijo" />
                            <x-text-input id="serie" name="serie" type="text" maxlength="10" class="mt-1 block w-full"
                                :value="old('serie', $correlativo->serie)" />
                            <x-input-error :messages="$errors->get('serie')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="ultimo_numero" value="Último número usado *" />
                            <x-text-input id="ultimo_numero" name="ultimo_numero" type="number" min="0" class="mt-1 block w-full"
                                :value="old('ultimo_numero', $correlativo->ultimo_numero ?? 0)" required />
                            <x-input-error :messages="$errors->get('ultimo_numero')" class="mt-1" />
                        </div>
                    </div>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $correlativo->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('configuracion.correlativos.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
