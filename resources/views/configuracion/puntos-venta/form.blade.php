<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Configuración &mdash; {{ $puntoVenta->exists ? 'Editar' : 'Nuevo' }} punto de venta
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @include('configuracion._nav')

                <form method="POST"
                      action="{{ $puntoVenta->exists ? route('configuracion.puntos-venta.update', $puntoVenta) : route('configuracion.puntos-venta.store') }}"
                      class="space-y-6">
                    @csrf
                    @if ($puntoVenta->exists) @method('PUT') @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <x-input-label for="establecimiento_id" value="Establecimiento *" />
                            <select id="establecimiento_id" name="establecimiento_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($establecimientos as $est)
                                    <option value="{{ $est->id }}" @selected(old('establecimiento_id', $puntoVenta->establecimiento_id) == $est->id)>{{ $est->codigo }} — {{ $est->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('establecimiento_id')" class="mt-1" />
                            @if ($establecimientos->isEmpty())
                                <p class="text-xs text-amber-600 mt-1">Primero registra un establecimiento.</p>
                            @endif
                        </div>
                        <div>
                            <x-input-label for="codigo" value="Código * (ej. P001)" />
                            <x-text-input id="codigo" name="codigo" type="text" maxlength="4" class="mt-1 block w-full"
                                :value="old('codigo', $puntoVenta->codigo)" required />
                            <x-input-error :messages="$errors->get('codigo')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="nombre" value="Nombre *" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full"
                                :value="old('nombre', $puntoVenta->nombre)" required />
                            <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="descripcion" value="Descripción" />
                            <x-text-input id="descripcion" name="descripcion" type="text" class="mt-1 block w-full"
                                :value="old('descripcion', $puntoVenta->descripcion)" />
                            <x-input-error :messages="$errors->get('descripcion')" class="mt-1" />
                        </div>
                    </div>

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $puntoVenta->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('configuracion.puntos-venta.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
