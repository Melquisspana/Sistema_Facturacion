<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Anti-flash: decide el tema ANTES de pintar (localStorage; oscuro por
             defecto). Sin esto se ve un parpadeo claro→oscuro en cada carga. --}}
        <script>
            (function () {
                var t = localStorage.getItem('dlg-theme');
                document.documentElement.classList.toggle('dark', t ? t === 'dark' : true);
            })();
        </script>

        <title>{{ \App\Support\NombreEmpresa::actual() }}</title>
        @include('layouts.partials.instalar-app')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>[x-cloak]{display:none !important;}</style>
        {{-- Los grupos colapsables de la barra lateral arrancan cerrados desde el
             servidor para no parpadear al cargar (ver components/sidebar-group).
             Sin JavaScript nadie los abriría, así que acá se vuelven a mostrar:
             sin JS se pierde el colapso, nunca los enlaces. --}}
        <noscript><style>[data-sidebar-panel]{display:block !important;}</style></noscript>
    </head>
    <body class="min-h-screen bg-gray-100 font-sans antialiased dark:bg-ink-950">
        {{-- <html> y <body> ya llevan el mismo fondo (body arriba; html vía app.css): si
             algún elemento absoluto sin contenedor propio (p. ej. un <label class="sr-only">
             huérfano) empuja el alto REAL del documento (<html> scrollHeight) más allá de
             este wrapper, el lienzo de abajo sigue siendo oscuro en vez de blanco. --}}
        <div class="min-h-screen bg-gray-100 dark:bg-ink-950">
            {{-- A pantalla completa (la fábrica) no hay barra lateral ni menú: solo una barra
                 chica que no queda fija, con la empresa, la persona y «Salir». --}}
            @if ($pantallaCompleta ?? false)
                @include('layouts.partials.barra-pantalla-completa')
            @else
                @include('layouts.navigation')
            @endif

            {{-- Contenido corrido: 4rem bajo la topbar fija y 16rem a la derecha de la sidebar en desktop.
                 En el apartado Configuración no hay barra lateral de área (su índice va
                 dentro de la página), así que el contenido usa todo el ancho. --}}
            <div class="{{ ($pantallaCompleta ?? false) ? '' : 'pt-16 '.(\App\Enums\AreaSistema::enApartadoConfiguracion() ? '' : 'lg:pl-64') }}">
                <x-aviso-contingencia />
                <!-- Page Heading -->
                @isset($header)
                    <header class="bg-white shadow dark:bg-ink-900 dark:shadow-none dark:border-b dark:border-ink-600">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>
        @include('layouts.partials.sw-contingencia')
    </body>
</html>
