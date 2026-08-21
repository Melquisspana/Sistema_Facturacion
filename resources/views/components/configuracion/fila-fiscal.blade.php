@props(['ajuste'])
{{--
    Una fila de las pantallas de facturación electrónica.

    Todo llega resuelto en el DTO (App\Ajustes\Fiscal\AjusteFiscal): esta vista no
    consulta configuración, no llama a servicios y no decide clasificaciones. Es
    deliberado — la misma razón por la que la tarjeta del Resumen tampoco calcula
    nada: lo que se calcula en una plantilla no se puede probar.

    NUNCA PINTA SECRETOS. El DTO no los lleva: de una credencial trae el texto
    «Configurada» o «Sin configurar», nunca el valor ni un relleno de puntos que
    insinúe su longitud.

    ORDEN DE LECTURA, de arriba abajo: qué es → cuánto vale → qué se puede hacer
    con ello → de dónde sale. El nombre técnico (DTE_TRANSMISION_DRY_RUN) va al
    final y en pequeño: le sirve a quien administra el servidor para cruzarlo con
    el archivo, pero no es lo que debe leerse primero.
--}}
<div class="py-3 {{ $ajuste->atencion ? '-mx-3 rounded-md bg-amber-50 px-3' : '' }}">
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <p class="min-w-0 text-sm font-medium text-gray-900">{{ $ajuste->etiqueta }}</p>
        <x-configuracion.badge-clasificacion :clasificacion="$ajuste->clasificacion" />
    </div>

    <p class="mt-1 text-sm {{ $ajuste->atencion ? 'font-medium text-amber-900' : 'text-gray-700' }}">
        {{ $ajuste->valor }}
    </p>

    @if ($ajuste->descripcion)
        <p class="mt-1 text-xs text-gray-500">{{ $ajuste->descripcion }}</p>
    @endif

    @if ($ajuste->nota)
        <p class="mt-1.5 rounded-md bg-blue-50 px-2.5 py-1.5 text-xs text-blue-800">{{ $ajuste->nota }}</p>
    @endif

    @if ($ajuste->fuente || $ajuste->env)
        <p class="mt-1 text-xs text-gray-400">
            @if ($ajuste->fuente)
                Fuente: {{ $ajuste->fuente }}
            @endif
            @if ($ajuste->env)
                @if ($ajuste->fuente) &middot; @endif
                <code>{{ $ajuste->env }}</code>
            @endif
        </p>
    @endif
</div>
