<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Editar salida</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <form method="POST" action="{{ route('rutas.salidas.update', $salida) }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                @csrf
                @method('PUT')
                @include('rutas.salidas._form')

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100 dark:border-ink-700">
                    <a href="{{ route('rutas.salidas.show', $salida) }}"
                       class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
