<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Anti-flash: mismo criterio que layouts/app.blade.php (oscuro por defecto). --}}
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
    <body class="font-sans text-gray-900 antialiased dark:text-paper-100">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-ink-950">
            <div class="flex items-center gap-2">
                <a href="/">
                    <x-application-logo class="w-16 h-16 fill-current text-gray-500 dark:text-paper-300" />
                </a>
                <x-theme-toggle class="ms-2" />
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg dark:bg-ink-800 dark:shadow-none dark:ring-1 dark:ring-ink-600">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
