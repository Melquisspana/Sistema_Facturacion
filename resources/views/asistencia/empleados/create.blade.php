<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Nuevo empleado</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('asistencia.empleados.store') }}"
                  class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                @csrf

                <p class="mb-5 text-sm text-gray-600 dark:text-paper-300">
                    Dar de alta a alguien no le permite marcar todavía: después hay que guardar su huella
                    <strong>en el sensor</strong> y anotar acá qué ranura le tocó. Eso se hace desde su ficha.
                </p>

                @include('asistencia.empleados._form', ['empleado' => $empleado])

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Crear</button>
                    <a href="{{ route('asistencia.empleados.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
