<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar cliente de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                Los cambios solo afectan a listas de empaque NUEVAS: las exportaciones ya creadas
                conservan el nombre, dirección y FDA con los que se generaron.
            </div>
            <form method="POST" action="{{ route('exportaciones.clientes.update', $cliente) }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
                @csrf
                @method('PUT')
                @include('exportaciones.clientes._form')

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                    <a href="{{ route('exportaciones.clientes.show', $cliente) }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
