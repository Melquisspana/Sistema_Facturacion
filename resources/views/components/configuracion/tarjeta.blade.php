@props(['tarjeta'])
{{--
    Una tarjeta del Resumen.

    Toda la información viene ya resuelta en el DTO (App\Ajustes\Resumen\TarjetaResumen):
    esta vista no consulta configuración, no llama a servicios y no decide estados.
    Es deliberado — un resumen que calcula cosas en la plantilla es un resumen
    imposible de probar.

    NUNCA PINTA SECRETOS: el DTO no los lleva. Las líneas dicen "Contraseña:
    configurada", nunca un valor ni un relleno de puntos que insinúe su longitud.

    El botón solo aparece si existe la pantalla correspondiente ($tarjeta->ruta).
--}}
<div class="flex h-full flex-col rounded-lg border border-gray-200 bg-white p-4">
    <div class="flex items-start justify-between gap-3">
        <h3 class="text-sm font-semibold text-gray-900">{{ $tarjeta->titulo }}</h3>
        <x-configuracion.badge :estado="$tarjeta->estado" />
    </div>

    <p class="mt-2 text-sm text-gray-600">{{ $tarjeta->detalle }}</p>

    @if ($tarjeta->lineas)
        <dl class="mt-3 space-y-1">
            @foreach ($tarjeta->lineas as $linea)
                <dd class="text-xs text-gray-500">{{ $linea }}</dd>
            @endforeach
        </dl>
    @endif

    @if ($tarjeta->advertencia)
        <p class="mt-3 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
            {{ $tarjeta->advertencia }}
        </p>
    @endif

    {{-- mt-auto: la fila inferior queda pegada abajo aunque las tarjetas de la
         cuadrícula tengan distinta cantidad de líneas. --}}
    <div class="mt-auto flex flex-wrap items-end justify-between gap-2 pt-3">
        <div class="text-xs text-gray-400">
            @if ($tarjeta->fuente)
                <p>Fuente: {{ $tarjeta->fuente }}</p>
            @endif

            @if ($tarjeta->ultimaVerificacion)
                {{-- Se muestra el texto relativo (que es lo que se lee) y el
                     timestamp exacto queda en el title, que es lo que se compara. --}}
                <p title="{{ $tarjeta->verificacionExacta() }}">
                    Última verificación: {{ $tarjeta->verificacionRelativa() }}
                    @if ($tarjeta->resultadoVerificacion)
                        &middot; {{ $tarjeta->resultadoVerificacion }}
                    @endif
                </p>
            @endif
        </div>

        @if ($tarjeta->ruta)
            <a href="{{ $tarjeta->ruta }}"
               class="shrink-0 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                {{ $tarjeta->etiquetaRuta ?? 'Configurar' }}
            </a>
        @endif
    </div>
</div>
