<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Configuración — Correo de DTE</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('configuracion.correo.update') }}" class="bg-white shadow sm:rounded-lg p-6 space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="auto_envio" value="1" @checked($autoEnvio) class="mt-1 rounded border-gray-300 text-indigo-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Enviar automáticamente el CCF al cliente al ser aceptado por MH</span>
                            <span class="block text-xs text-gray-500">Si está desactivado, el envío es manual desde la pantalla del documento.</span>
                        </span>
                    </label>
                </div>

                <div>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="adjuntar_jws" value="1" @checked($adjuntarJws) class="mt-1 rounded border-gray-300 text-indigo-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Adjuntar el JWS firmado</span>
                            <span class="block text-xs text-gray-500">El PDF y el JSON oficial se adjuntan siempre; el JWS es opcional.</span>
                        </span>
                    </label>
                </div>

                <div>
                    <label for="plantilla" class="block text-sm font-medium text-gray-700 mb-1">Plantilla del cuerpo del correo</label>
                    <textarea id="plantilla" name="plantilla" rows="10"
                              class="w-full rounded-md border-gray-300 text-sm font-mono">{{ old('plantilla', $plantilla) }}</textarea>
                    <div class="mt-2 text-xs text-gray-500">
                        Variables disponibles:
                        @foreach ($variables as $v)
                            <code class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1 text-gray-700">{{ $v }}</code>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
