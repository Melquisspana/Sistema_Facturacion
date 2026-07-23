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

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>[x-cloak]{display:none !important;}</style>
    </head>
    <body class="min-h-screen bg-gray-100 font-sans antialiased dark:bg-ink-950">
        {{-- <html> y <body> ya llevan el mismo fondo (body arriba; html vía app.css): si
             algún elemento absoluto sin contenedor propio (p. ej. un <label class="sr-only">
             huérfano) empuja el alto REAL del documento (<html> scrollHeight) más allá de
             este wrapper, el lienzo de abajo sigue siendo oscuro en vez de blanco. --}}
        <div class="min-h-screen bg-gray-100 dark:bg-ink-950">
            @include('layouts.navigation')

            {{-- Contenido corrido: 4rem bajo la topbar fija y 16rem a la derecha de la sidebar en desktop. --}}
            <div class="pt-16 lg:pl-64">
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
    </body>
</html>
