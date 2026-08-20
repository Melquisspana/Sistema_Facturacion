@props(['titulo', 'descripcion' => null])
{{--
    Tarjeta de una subsección dentro de una pantalla de configuración (Servidor
    SMTP, Documentos fiscales, Contabilidad...).

    Existe para que la pantalla de Correo pueda agrupar tres cosas distintas sin
    que cada bloque invente su propio encabezado, su propio espaciado y su propio
    borde. El slot `acciones` es para lo que va arriba a la derecha (un badge de
    estado, un enlace secundario).
--}}
<section {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-white p-5']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 pb-3">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-gray-900">{{ $titulo }}</h3>
            @if ($descripcion)
                <p class="mt-1 text-sm text-gray-500">{{ $descripcion }}</p>
            @endif
        </div>

        @isset($acciones)
            <div class="flex shrink-0 items-center gap-2">{{ $acciones }}</div>
        @endisset
    </div>

    <div class="pt-4">
        {{ $slot }}
    </div>
</section>
