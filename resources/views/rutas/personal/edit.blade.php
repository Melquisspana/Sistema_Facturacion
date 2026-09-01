<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">{{ $personal->nombre }}</h2>
            <a href="{{ route('rutas.personal.show', $personal) }}"
               class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">
                ← Volver a la ficha
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('rutas.personal.update', $personal) }}"
                  class="space-y-6 bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:rounded-xl dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                @csrf @method('PUT')

                @include('rutas.personal._form')

                <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-ink-700">
                    <a href="{{ route('rutas.personal.show', $personal) }}"
                       class="text-sm text-gray-600 hover:underline dark:text-paper-300">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
