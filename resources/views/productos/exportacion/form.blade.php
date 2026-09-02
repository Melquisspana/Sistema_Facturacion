@php
    $esNuevo = ! $producto->exists;
    $accion = $esNuevo
        ? route('productos.exportacion.store')
        : route('productos.exportacion.update', $producto);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $esNuevo ? 'Nuevo producto de exportación' : 'Editar '.$producto->nombre_es }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @unless ($esNuevo)
                    <div class="mb-5 rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                        Cambiar estos datos <strong>no toca ninguna lista de empaque ya creada</strong>: cada lista guarda su
                        propio snapshot del producto en el momento de agregarlo, precisamente para que corregir el catálogo
                        no reescriba documentos que ya se enviaron.
                    </div>
                @endunless

                <form method="POST" action="{{ $accion }}" class="space-y-5">
                    @csrf
                    @unless ($esNuevo) @method('PUT') @endunless

                    @include('productos.exportacion._campos', ['p' => $producto])

                    <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                        <x-primary-button>{{ $esNuevo ? 'Crear producto' : 'Guardar cambios' }}</x-primary-button>
                        <a href="{{ $esNuevo ? route('productos.exportacion.index') : route('productos.exportacion.show', $producto) }}"
                           class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
