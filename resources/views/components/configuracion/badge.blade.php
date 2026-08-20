@props(['estado'])
{{--
    Badge de estado del Centro de Configuración.

    Las clases NO se deciden aquí: salen de EstadoTarjeta::clases(), para que los
    siete estados se pinten igual en cualquier pantalla que los use y añadir uno
    nuevo no obligue a repasar las vistas.

    Colores de la paleta estándar de Tailwind a propósito: los overrides de
    resources/css/app.css los reteman en modo oscuro sin que esta vista necesite
    una sola clase dark:.
--}}
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$estado->clases()]) }}>
    {{ $estado->etiqueta() }}
</span>
