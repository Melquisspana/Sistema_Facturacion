<x-configuracion-layout titulo="Tienda web">
    <div class="space-y-6">
        <x-configuracion.seccion titulo="Estado de la integración" descripcion="Los pedidos pagados se traen a la fábrica.">
            <x-planta.badge :color="$completa ? 'green' : 'amber'">
                {{ $completa ? 'Configuración completa' : 'Falta configurar' }}
            </x-planta.badge>
            <p class="mt-3 text-sm text-gray-700">URL: {{ $url->valor ?: 'Sin configurar' }}</p>
        </x-configuracion.seccion>
        <x-configuracion.seccion titulo="Conexión y lectura" descripcion="La lectura automática empieza apagada.">
            <form method="POST" action="{{ route('configuracion.integraciones.tienda.update') }}" class="space-y-5">
                @csrf
                @method('PUT')
                <x-configuracion.campo nombre="url" etiqueta="URL de la tienda (opcional)" :estado="$url">
                    <x-text-input id="url" name="url" type="url" maxlength="255" class="min-h-11 w-full" :value="old('url', $url->valor)" />
                </x-configuracion.campo>
                <x-configuracion.campo nombre="automatica" etiqueta="Traer pedidos automáticamente" :estado="$automatica">
                    <input type="hidden" name="automatica" value="0">
                    <input id="automatica" name="automatica" type="checkbox" value="1" @checked(old('automatica', $automatica->valor)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                </x-configuracion.campo>
                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <x-primary-button>Guardar cambios</x-primary-button>
                </div>
            </form>
        </x-configuracion.seccion>
        <x-configuracion.seccion titulo="Secreto de la tienda" descripcion="El valor se guarda cifrado y nunca se muestra.">
            <p class="mb-3 text-sm text-gray-700">{{ $secreto->configurado ? 'Configurado' : 'Sin configurar' }}</p>
            {{-- El componente secundario todavía solo dibuja button: enlace como las integraciones vecinas. --}}
            <a href="{{ route('configuracion.integraciones.tienda.secreto.edit') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ $secreto->configurado ? 'Reemplazar secreto' : 'Configurar secreto' }}
            </a>
        </x-configuracion.seccion>
    </div>
</x-configuracion-layout>
