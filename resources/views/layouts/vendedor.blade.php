<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mi salida · {{ config('app.name') }}</title>
    <script>
        (function () {
            var t = localStorage.getItem('dlg-theme');
            document.documentElement.classList.toggle('dark', t ? t === 'dark' : true);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :where(a, button, input, summary):focus-visible { outline: 3px solid #16a34a; outline-offset: 3px; }
    </style>
</head>
<body class="min-h-screen bg-gray-100 font-sans text-ink-900 antialiased dark:bg-ink-950 dark:text-paper-100">
    <header class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
        <span class="min-w-0 truncate text-sm">{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="min-h-11 rounded-lg px-4 text-sm font-medium underline">Salir</button>
        </form>
    </header>
    <main class="mx-auto max-w-3xl px-4 pb-8">
        <x-rutas.avisos />
        @yield('content')
    </main>
</body>
</html>
