@props(['clasificacion'])
{{--
    Qué se puede hacer con un parámetro fiscal: solo lectura, editable, editable
    con confirmación, crítico o solo en el servidor.

    Las clases salen de ClasificacionFiscal::clases(), no de aquí, por el mismo
    motivo que el badge de estado: cinco valores pintados en cuatro pantallas
    tienen que verse igual en las cuatro, y añadir un sexto no puede obligar a
    repasar las vistas una por una.

    El `title` lleva la explicación larga: el badge cabe en dos palabras y la
    diferencia entre "solo lectura" y "solo en el servidor" no cabe en dos
    palabras.
--}}
<span title="{{ $clasificacion->explicacion() }}"
      {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$clasificacion->clases()]) }}>
    {{ $clasificacion->etiqueta() }}
</span>
