<x-configuracion-layout :titulo="$definicion->etiqueta">
    {{--
        Pantalla ÚNICA para reemplazar o quitar cualquier secreto administrable.

        Esta vista NO recibe el valor: recibe un App\Ajustes\EstadoAjuste, que para
        un secreto se construye con el valor en null. No hay forma de imprimirlo
        aunque alguien lo intente.

        El campo nunca se precarga, ni con el valor ni con un relleno que insinúe su
        longitud. `autocomplete="new-password"` impide que el navegador ofrezca la
        contraseña de la SESIÓN del usuario, que es lo que hace con
        `current-password` y sería un modo bastante tonto de acabar guardando la
        credencial equivocada.
    --}}

    <div class="mx-auto max-w-2xl space-y-6">

        {{-- ---- Estado actual: si está y de dónde sale. Nada más. ---- --}}
        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-800">Estado actual</p>
                    <p class="mt-0.5 text-sm text-gray-600">
                        @if ($estado->configurado)
                            {{-- Puntos DECORATIVOS de longitud fija: no dicen nada del valor. --}}
                            <span class="tracking-widest">••••••••</span>
                            &middot; Configurada
                        @else
                            Sin configurar
                        @endif
                    </p>
                </div>
                <p class="text-xs text-gray-500">Fuente: {{ $estado->fuente->etiqueta() }}</p>
            </div>

            @if ($definicion->descripcion)
                <p class="mt-3 text-xs text-gray-500">{{ $definicion->descripcion }}</p>
            @endif
        </div>

        {{-- ---- Reemplazar ---- --}}
        <form method="POST" action="{{ $rutas['update'] }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p class="font-semibold">Qué pasa al guardar</p>
                <p class="mt-1">
                    El valor se guardará cifrado dentro de la aplicación y pasará a usarse en lugar del
                    archivo de configuración. Si no es correcto, el servicio rechazará la autenticación.
                    El valor anterior no se muestra y no se puede recuperar desde aquí.
                </p>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">{{ $definicion->etiqueta }} (valor nuevo)</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" autocapitalize="off" spellcheck="false"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm">
                <p class="mt-1 text-xs text-gray-500">
                    El campo empieza siempre vacío. Dejarlo en blanco no borra el valor actual: el
                    formulario se rechaza.
                </p>
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Confirmación N2 en la misma pantalla: la consecuencia está arriba, a
                 la vista, y esta casilla es el segundo acto deliberado. No se usa la
                 pantalla de confirmación genérica porque esa reenvía los valores en
                 campos ocultos, y un secreto no puede hacer ese viaje. --}}
            <label class="flex items-start gap-3">
                <input type="checkbox" name="confirmacion" value="1" class="mt-1 rounded border-gray-300 text-indigo-600">
                <span class="text-sm text-gray-700">
                    Confirmo que quiero reemplazar «{{ $definicion->etiqueta }}».
                </span>
            </label>
            @error('confirmacion') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ $volver }}"
                   class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancelar
                </a>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Guardar
                </button>
            </div>
        </form>

        {{-- ---- Quitar el override ---- --}}
        @if ($hayOverride)
            <form method="POST" action="{{ $rutas['destroy'] }}" class="space-y-3 rounded-md border border-gray-200 p-4">
                @csrf
                @method('DELETE')

                <div>
                    <p class="text-sm font-medium text-gray-800">Volver al valor del archivo de configuración</p>
                    <p class="mt-1 text-sm text-gray-500">
                        Borra únicamente el valor guardado en la aplicación. El archivo de configuración
                        del servidor no se toca: a partir de ese momento vuelve a usarse el que haya allí.
                    </p>
                </div>

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="confirmacion" value="1" class="mt-1 rounded border-gray-300 text-indigo-600">
                    <span class="text-sm text-gray-700">
                        Confirmo que quiero dejar de usar el valor guardado en la aplicación.
                    </span>
                </label>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Quitar valor guardado
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-configuracion-layout>
