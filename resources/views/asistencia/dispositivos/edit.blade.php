<x-configuracion-layout titulo="Lectores de huella">
    <div class="mb-5">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
            Editar lector &mdash; {{ $dispositivo->nombre }}
        </h2>
    </div>

    <div>
        <div class="max-w-2xl">
            <form method="POST" action="{{ route('configuracion.lectores.update', $dispositivo) }}"
                  class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                @csrf
                @method('PUT')

                {{-- El aviso importa: el firmware manda el código ANTERIOR en cada
                     petición, así que cambiarlo lo deja sin autenticar hasta que
                     alguien lo reprograme. Editar el nombre no tiene ese efecto. --}}
                <p class="mb-5 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    Cambiar el <strong>código</strong> deja al lector sin autenticar hasta que se actualice
                    también en el firmware. El nombre se puede cambiar sin consecuencias.
                    Esta pantalla <strong>no toca el token</strong>.
                </p>

                @include('asistencia.dispositivos._form', ['dispositivo' => $dispositivo])

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
                    <a href="{{ route('configuracion.lectores.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-configuracion-layout>
