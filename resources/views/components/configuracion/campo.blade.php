@props(['nombre', 'etiqueta', 'estado' => null, 'ayuda' => null, 'claveTecnica' => null])
{{--
    Un campo de configuración con su etiqueta HUMANA, su ayuda, su error y —debajo,
    en pequeño— de dónde sale el valor que se está mostrando.

    LOS NOMBRES VISIBLES SON HUMANOS: «Servidor», «Puerto», «Contraseña». La clave
    técnica (MAIL_HOST, mail.smtp.host) se muestra solo si se pide expresamente,
    porque a quien administra el servidor sí le sirve para cruzar con el .env — pero
    no es lo que debe leer primero quien entra a configurar el correo.

    `$estado` es un App\Ajustes\EstadoAjuste: se usa únicamente para el rótulo de
    fuente. Para un secreto ese objeto no lleva el valor, así que este componente no
    tiene forma de imprimirlo aunque se le pase.
--}}
<div>
    <label for="{{ $nombre }}" class="block text-sm font-medium text-gray-700">
        {{ $etiqueta }}
    </label>

    <div class="mt-1">
        {{ $slot }}
    </div>

    @if ($ayuda)
        <p class="mt-1 text-xs text-gray-500">{{ $ayuda }}</p>
    @endif

    @error($nombre)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror

    @if ($estado || $claveTecnica)
        <p class="mt-1 text-xs text-gray-400">
            @if ($estado)
                Fuente: {{ $estado->fuente->etiqueta() }}
            @endif
            @if ($claveTecnica)
                @if ($estado) &middot; @endif
                <code>{{ $claveTecnica }}</code>
            @endif
        </p>
    @endif
</div>
