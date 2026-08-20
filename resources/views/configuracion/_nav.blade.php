@php
    $tabs = [
        ['ruta' => 'configuracion.empresa.edit', 'patron' => 'configuracion.empresa.*', 'titulo' => 'Empresa emisora'],
        ['ruta' => 'configuracion.establecimientos.index', 'patron' => 'configuracion.establecimientos.*', 'titulo' => 'Establecimientos'],
        ['ruta' => 'configuracion.puntos-venta.index', 'patron' => 'configuracion.puntos-venta.*', 'titulo' => 'Puntos de venta'],
        ['ruta' => 'configuracion.correlativos.index', 'patron' => 'configuracion.correlativos.*', 'titulo' => 'Correlativos'],
        ['ruta' => 'configuracion.contabilidad.edit', 'patron' => 'configuracion.contabilidad.*', 'titulo' => 'Contabilidad'],
        // Correo: la pantalla existía (controlador, vista y rutas GET/PUT) pero no
        // estaba en ninguna pestaña ni enlace, así que solo se llegaba escribiendo
        // la URL. Mismo permiso que el resto del grupo (configuracion.gestionar):
        // no se abre acceso nuevo, se cierra un hueco de navegación.
        ['ruta' => 'configuracion.correo.edit', 'patron' => 'configuracion.correo.*', 'titulo' => 'Correo'],
    ];
@endphp

{{-- FUENTE ÚNICA de las pestañas del Centro de Configuración. Ninguna vista dibuja
     este HTML por su cuenta: todas lo reciben a través de <x-configuracion-layout>,
     así que agregar o renombrar una sección se hace en un solo sitio.

     UNA SOLA FILA, siempre. En escritorio las seis pestañas caben de sobra; cuando
     no caben (móvil, ventana angosta) la barra se DESPLAZA en horizontal en vez de
     partirse, que es lo que dejaba una pestaña suelta en un segundo renglón:
     `shrink-0` impide que se compriman y `whitespace-nowrap` que se corten.

     El borde inferior de esta barra es además el SEPARADOR entre la navegación y el
     contenido de cada pantalla; por eso vive acá y no en el shell.

     Solo navegación: mismas rutas y mismo permiso de siempre. El patrón `.*` deja la
     pestaña activa también en las subpantallas (crear/editar) de cada sección. --}}
<nav aria-label="Secciones de configuración"
     class="flex gap-1 overflow-x-auto border-b border-gray-200 px-6 [scrollbar-width:thin]">
    @foreach ($tabs as $tab)
        @php $activo = request()->routeIs($tab['patron']); @endphp
        <a href="{{ route($tab['ruta']) }}"
           @if ($activo) aria-current="page" @endif
           class="shrink-0 whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition {{ $activo
               ? 'border-indigo-600 text-indigo-600'
               : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
            {{ $tab['titulo'] }}
        </a>
    @endforeach
</nav>
