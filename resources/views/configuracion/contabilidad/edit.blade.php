<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Configuración — Contabilidad</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('configuracion._nav')

            <form method="POST" action="{{ route('configuracion.contabilidad.update') }}" class="bg-white shadow sm:rounded-lg p-6 space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="correo_contabilidad" class="block text-sm font-medium text-gray-700 mb-1">Correo de contabilidad</label>
                    <input type="email" id="correo_contabilidad" name="correo_contabilidad"
                           value="{{ old('correo_contabilidad', $correoContabilidad) }}"
                           class="w-full rounded-md border-gray-300 text-sm" placeholder="contabilidad@empresa.com">
                    <p class="mt-1 text-xs text-gray-500">A esta dirección se enviará la copia (BCC) de los documentos.</p>
                    @error('correo_contabilidad') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="flex items-start gap-3">
                        <input type="hidden" name="enviar_copia_contabilidad" value="0">
                        <input type="checkbox" name="enviar_copia_contabilidad" value="1" @checked($enviarCopia)
                               class="mt-1 rounded border-gray-300 text-indigo-600">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Enviar copia a contabilidad</span>
                            <span class="block text-xs text-gray-500">
                                Cuando esté activo, cada vez que se use "Enviar correo" de un documento (CCF, factura,
                                nota de crédito o exportación), se añadirá una copia oculta (BCC) al correo de contabilidad.
                            </span>
                        </span>
                    </label>
                </div>

                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-xs text-blue-800">
                    Guardar esta configuración <span class="font-semibold">no envía ningún correo</span>. La copia solo
                    viaja dentro del envío manual existente de cada documento; no se envía nada automático ni histórico.
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
