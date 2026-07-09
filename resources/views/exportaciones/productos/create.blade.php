<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nuevo producto de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('exportaciones.productos.store') }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
                @csrf
                @include('exportaciones.productos._form')

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                    <a href="{{ route('exportaciones.productos.index') }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar producto</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
