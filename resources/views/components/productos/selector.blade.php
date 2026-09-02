@props(['activo' => 'nacionales'])

{{--
    Selector de la entrada única «Productos».

    Hay UNA sola entrada en el menú y dos clases de producto detrás. Nacionales es
    lo predeterminado —es lo que se abre al entrar y lo que se usa todos los días—
    y exportación es la segunda pestaña.

    NO es un <select> ni un desplegable: son dos destinos permanentes y navegables,
    así que son enlaces. Eso les da, gratis, lo que un widget de JavaScript habría
    que reimplementar — se abren en pestaña nueva, se pueden marcar como favorito,
    funcionan sin JS y ya son tabulables.

    Accesibilidad:
      · <nav> con nombre propio, para que un lector de pantalla lo anuncie como una
        navegación y no como dos enlaces sueltos;
      · aria-current="page" en el activo: es lo que convierte el subrayado en
        información y no solo en color;
      · el estado activo se distingue por peso, color Y línea inferior, nunca solo
        por color;
      · foco visible propio, porque el borde inferior se come el outline por
        defecto en algunos navegadores.

    La pestaña de exportación solo se dibuja con permiso `exportaciones.ver`: quien
    no lo tiene ve la pantalla de nacionales de siempre, sin una puerta que no
    puede cruzar. Ocultar no autoriza — el candado real está en el middleware de
    cada ruta.
--}}

@php
    $base = 'inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-1 pb-2.5 pt-1 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 focus-visible:rounded-sm';
    $encendido = 'border-indigo-600 font-semibold text-indigo-700';
    $apagado = 'border-transparent font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700';
@endphp

<nav aria-label="Tipo de producto" class="mb-6 border-b border-gray-200">
    {{-- overflow-x-auto en el CONTENEDOR de las pestañas y no en la página: en un
         móvil estrecho los dos rótulos no caben, y lo que debe desplazarse es esta
         tira, nunca el documento entero. --}}
    <div class="-mb-px flex gap-6 overflow-x-auto">
        <a href="{{ route('productos.index') }}"
           @class([$base, $encendido => $activo === 'nacionales', $apagado => $activo !== 'nacionales'])
           @if ($activo === 'nacionales') aria-current="page" @endif>
            Productos nacionales
        </a>

        @can('exportaciones.ver')
            <a href="{{ route('productos.exportacion.index') }}"
               @class([$base, $encendido => $activo === 'exportacion', $apagado => $activo !== 'exportacion'])
               @if ($activo === 'exportacion') aria-current="page" @endif>
                Productos de exportación
            </a>
        @endcan
    </div>
</nav>
