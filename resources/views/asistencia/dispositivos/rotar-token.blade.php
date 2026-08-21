{{-- CONFIRMACIÓN FUERTE de la rotación del token.

     Por qué una pantalla y no un `confirm()` del navegador: rotar deja al lector
     de la puerta SIN AUTENTICAR hasta que alguien reprograme el firmware, y
     mientras tanto nadie puede marcar. Un cuadro de diálogo que se acepta con
     Enter sin leerlo no está a la altura de eso.

     Se exige ESCRIBIR el código del lector: es el mismo criterio de la
     confirmación fuerte del Centro de Configuración. Quien escribe el código leyó
     de qué lector se trata, que es justo el error que hay que evitar cuando hay
     varios y se entra desde un listado.

     La comprobación real está en el controlador, no en este formulario. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
            Rotar el token &mdash; {{ $dispositivo->nombre }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-red-200 dark:bg-ink-800 dark:ring-red-500/30">

                <div class="rounded-md bg-red-50 p-4 dark:bg-red-500/10">
                    <p class="text-sm font-semibold text-red-800 dark:text-red-300">Esto deja el lector fuera de servicio hasta que actualices el firmware</p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700 dark:text-red-300">
                        <li>El token actual <strong>deja de funcionar en el acto</strong>.</li>
                        <li>El lector responderá «no autorizado» y <strong>nadie podrá marcar</strong> en él.</li>
                        <li>El token nuevo se muestra <strong>una sola vez</strong>: hay que copiarlo al ESP32.</li>
                        <li>El token anterior <strong>no se puede recuperar</strong>. Nunca se guardó.</li>
                    </ul>
                </div>

                <dl class="mt-5 space-y-1 text-sm">
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Lector:</dt>
                        <dd class="font-medium text-gray-800 dark:text-paper-100">{{ $dispositivo->nombre }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Código:</dt>
                        <dd><code class="text-gray-800 dark:text-paper-100">{{ $dispositivo->codigo }}</code></dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Última conexión:</dt>
                        <dd class="text-gray-800 dark:text-paper-200">
                            {{ $dispositivo->ultima_conexion_at?->format('d/m/Y H:i') ?? 'nunca se ha comunicado' }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-gray-500 dark:text-paper-400">Ranuras asignadas:</dt>
                        <dd class="text-gray-800 dark:text-paper-200">{{ $dispositivo->huellas()->where('activo', true)->count() }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('asistencia.dispositivos.rotar-token.ejecutar', $dispositivo) }}" class="mt-6">
                    @csrf

                    <label for="confirmacion" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
                        Para confirmar, escribí el código del lector: <code class="text-gray-900 dark:text-paper-100">{{ $dispositivo->codigo }}</code>
                    </label>
                    <input id="confirmacion" name="confirmacion" type="text" required autocomplete="off" autofocus
                           value="{{ old('confirmacion') }}"
                           class="mt-2 block w-full rounded-md border-gray-300 font-mono text-sm dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                    @error('confirmacion')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <div class="mt-6 flex items-center gap-3">
                        <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                            Rotar el token
                        </button>
                        <a href="{{ route('asistencia.dispositivos.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-400">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
