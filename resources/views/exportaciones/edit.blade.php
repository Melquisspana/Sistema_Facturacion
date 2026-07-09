<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar lista de empaque #{{ $exportacion->id }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('exportaciones.update', $exportacion) }}" class="space-y-6">
                @csrf
                @method('PUT')
                @include('exportaciones._form')

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('exportaciones.show', $exportacion) }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
