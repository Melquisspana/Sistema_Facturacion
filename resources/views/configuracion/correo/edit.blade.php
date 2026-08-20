<x-configuracion-layout titulo="Correo de DTE">
    {{-- El aviso de «guardado» ya lo pinta el shell (igual que en las otras cinco
         pantallas); esta vista lo dibujaba por su cuenta y era la única que no
         incluía la navegación interna. --}}
    <form method="POST" action="{{ route('configuracion.correo.update') }}" class="max-w-3xl space-y-6">
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
</x-configuracion-layout>
