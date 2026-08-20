@props(['titulo'])
{{--
    Shell ÚNICO del Centro de Configuración. Todas las pantallas de
    /configuracion/* se envuelven en este componente, así que el ancho del
    módulo, la posición de las pestañas, el separador, el padding y la tarjeta
    son los mismos en todas — sin que ninguna vista tenga que repetir el HTML.

    Composición fija:

        Título de página            (cabecera del layout de la app)
        └ Contenedor del módulo     (max-w-6xl, mismo en las seis pantallas)
          └ Tarjeta
            ├ Navegación interna    (configuracion/_nav.blade.php: fuente única)
            ├ Separador             (el borde inferior de esa navegación)
            └ Contenido             (avisos + lo propio de cada pantalla)

    El ANCHO INTERNO del contenido no se impone: cada pantalla configura cosas
    distintas y un formulario de dos campos no tiene por qué medir lo mismo que
    una tabla de correlativos. Lo que se unifica es el marco, no el formulario.

    Esto es presentación pura: no toca rutas, permisos (el grupo entero va con
    `permission:configuracion.gestionar`), controladores ni valores guardados.
--}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
            Configuración &mdash; {{ $titulo }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
            {{-- overflow-hidden: recorta la barra de pestañas contra las esquinas
                 redondeadas cuando se desplaza en horizontal. --}}
            <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                @include('configuracion._nav')

                <div class="p-6">
                    {{-- Avisos del módulo. Viven en el shell (y no en cada vista) para
                         que aparezcan en el mismo sitio en las seis pantallas. --}}
                    @if (session('status'))
                        <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <p class="font-medium">Corrige los siguientes errores:</p>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
