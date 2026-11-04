<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Armar salida</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />

            <form method="POST" action="{{ route('rutas.salidas.store') }}"
                  class="rounded-lg border border-gray-200 bg-white p-6 space-y-5">
                @csrf
                @include('rutas.salidas._form')

                {{-- Cancelar a la izquierda y el principal a la derecha; en el celular, uno arriba del otro. --}}
                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <a href="{{ route('rutas.dashboard') }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                    <button class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Armar salida</button>
                </div>
            </form>

            <p class="mt-3 text-xs text-gray-500">
                Los CCF por entregar de la ruta se cargan solos al armarla.
            </p>
        </div>
    </div>
</x-app-layout>
