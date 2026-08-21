<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
            Editar &mdash; {{ $empleado->nombreCompleto() }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('asistencia.empleados.update', $empleado) }}"
                  class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                @csrf
                @method('PUT')

                {{-- Cambiar el nombre NO cambia de quién son las marcaciones: siguen
                     colgando de la misma fila. Se dice porque es la duda razonable
                     al corregir un apellido mal escrito. --}}
                <p class="mb-5 text-sm text-gray-600 dark:text-paper-300">
                    Corregir estos datos no altera ninguna marcación ya registrada ni sus ranuras asignadas.
                </p>

                @include('asistencia.empleados._form', ['empleado' => $empleado])

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
                    <a href="{{ route('asistencia.empleados.show', $empleado) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
