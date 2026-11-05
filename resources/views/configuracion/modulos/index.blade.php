<x-configuracion-layout titulo="Módulos">
    {{--
        Prender y apagar los módulos para toda la empresa.

        Cada casilla es un ajuste del Centro de Configuración (App\Enums\ModuloSistema):
        lo que se guarda acá manda sobre el .env del servidor. Prender guarda directo;
        apagar pide confirmación y dice qué deja de verse. Los datos nunca se borran.

        La fuente se muestra debajo de cada módulo para que quien administra el
        servidor sepa si el valor sale del .env o de esta pantalla.
    --}}
    <x-configuracion.seccion
        titulo="Módulos del sistema"
        descripcion="Prendé o apagá cada módulo para toda la empresa. Apagar uno lo saca del menú y cierra sus pantallas; los datos no se borran y vuelven a verse al encenderlo.">

        <form method="POST" action="{{ route('configuracion.modulos.update') }}">
            @csrf
            @method('PUT')

            <ul class="divide-y divide-gray-100">
                @foreach ($modulos as $fila)
                    @php
                        $modulo = $fila['modulo'];
                        $estado = $fila['estado'];
                        $encendido = (bool) old($modulo->value, $estado->valor);
                    @endphp
                    <li class="py-4">
                        <label for="modulo-{{ $modulo->value }}" class="flex items-start gap-3">
                            <input type="checkbox" id="modulo-{{ $modulo->value }}" name="{{ $modulo->value }}" value="1"
                                   @checked($encendido)
                                   class="mt-0.5 h-5 w-5 rounded border-gray-300 text-indigo-600">
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-gray-800">{{ $modulo->etiqueta() }}</span>
                                    <x-configuracion.badge :estado="$estado->valor
                                        ? \App\Ajustes\Resumen\EstadoTarjeta::Activo
                                        : \App\Ajustes\Resumen\EstadoTarjeta::Desactivado" />
                                </span>
                                <span class="mt-0.5 block text-sm text-gray-500">{{ $modulo->descripcion() }}</span>
                                <span class="mt-1 block text-xs text-gray-400">
                                    Fuente: {{ $estado->fuente->etiqueta() }} &middot; <code>{{ $modulo->variableEntorno() }}</code>
                                </span>
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>

            @error('modulos')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                <a href="{{ route('configuracion.modulos') }}"
                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancelar
                </a>
                <button type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                    Guardar módulos
                </button>
            </div>
        </form>
    </x-configuracion.seccion>
</x-configuracion-layout>
