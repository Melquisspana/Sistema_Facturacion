<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Nuevo lector de huella</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('asistencia.dispositivos.store') }}"
                  class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                @csrf

                <p class="mb-5 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    Al crearlo se genera su <strong>token</strong> y se muestra <strong>una sola vez</strong>.
                    Copialo al firmware del ESP32 antes de salir de la pantalla siguiente: no hay forma de
                    volver a verlo.
                </p>

                @include('asistencia.dispositivos._form', ['dispositivo' => $dispositivo])

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Crear y generar token</button>
                    <a href="{{ route('asistencia.dispositivos.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
