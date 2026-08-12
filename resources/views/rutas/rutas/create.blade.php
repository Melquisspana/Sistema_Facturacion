<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight dark:text-paper-100">Nueva ruta</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <form method="POST" action="{{ route('rutas.rutas.store') }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5 dark:bg-ink-800 dark:ring-ink-600 dark:shadow-none">
                @csrf
                @include('rutas.rutas._form')

                <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100 dark:border-ink-700">
                    <a href="{{ route('rutas.rutas.index') }}"
                       class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-ink-700 dark:text-paper-200 dark:hover:bg-ink-600">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar ruta</button>
                </div>
            </form>

            <p class="mt-3 text-xs text-gray-400 dark:text-paper-500">
                Después de guardarla vas a poder asignarle sus salas habituales.
            </p>
        </div>
    </div>
</x-app-layout>
