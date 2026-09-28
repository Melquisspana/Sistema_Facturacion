{{-- Editar un producto base: nombres y categoría. Las presentaciones toman el nombre nuevo. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar {{ $base->nombre_es }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('productos.exportacion.base.update', $base) }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
                @csrf @method('PUT')

                @include('productos.exportacion._campos_base', ['base' => $base])

                <p class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                    El nombre nuevo pasa a sus {{ $base->presentaciones->count() }} presentaciones y sale así en las listas
                    que se armen de acá en adelante. Las listas ya hechas conservan el nombre con el que se armaron.
                </p>

                <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                    <x-primary-button>Guardar cambios</x-primary-button>
                    <a href="{{ route('productos.exportacion.index') }}#producto-{{ $base->id }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
