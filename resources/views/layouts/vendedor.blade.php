<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Rutas') · {{ \App\Support\NombreEmpresa::actual() }}</title>
    <script>
        (function () {
            var t = localStorage.getItem('dlg-theme');
            document.documentElement.classList.toggle('dark', t ? t === 'dark' : true);
        })();
        // Si el celular devuelve la pantalla desde su memoria al tocar «atrás», se pide
        // de nuevo: así no muestra como pendiente lo que ya se marcó o se cerró.
        window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :where(a, button, input, summary):focus-visible { outline: 3px solid #16a34a; outline-offset: 3px; }
    </style>
</head>
<body class="min-h-screen bg-gray-100 font-sans text-ink-900 antialiased dark:bg-ink-950 dark:text-paper-100">
    <header class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
        <span class="min-w-0 truncate text-sm">{{ auth()->user()->name }}</span>
        <div class="flex shrink-0 items-center">
            {{-- El jefe de vendedores también sale a ruta: desde acá vuelve a su tablero. --}}
            @can('rutas.ver')
                <a href="{{ route('rutas.dashboard') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium underline">Volver al tablero</a>
            @endcan
            {{-- Un segundo toque mientras sale no manda otro cierre (con el token ya vencido). Anota la
                 hora de salida para que «atrás» pida de nuevo las pantallas de antes (layouts/partials/salio-atras). --}}
            <form method="POST" action="{{ route('logout') }}" onsubmit="if (this.dataset.saliendo) return false; this.dataset.saliendo = '1'; try { localStorage.setItem('dlg-salio', String(Date.now())); } catch (error) {}">
                @csrf
                <button class="min-h-11 rounded-lg px-4 text-sm font-medium underline">Salir</button>
            </form>
        </div>
    </header>
    <main class="mx-auto max-w-3xl px-4 pb-8">
        {{-- El menú del vendedor (flujo único, 10/11/2026): Inicio, Sacar pedido y Por entregar.
             Cada título en una línea, también en el celular más angosto. --}}
        @can('rutas.entregas.registrar')
            @php
                $pantallas = array_filter([
                    'rutas.mi-salida.index' => 'Inicio',
                    'rutas.sacar-pedido.index' => auth()->user()->can('rutas.pedidos.registrar') ? 'Sacar pedido' : null,
                    'rutas.por-entregar.index' => 'Por entregar',
                ]);
            @endphp
            <nav aria-label="Pantallas del vendedor" class="mb-4 grid gap-2 {{ count($pantallas) === 3 ? 'grid-cols-3' : 'grid-cols-2' }}">
                @foreach ($pantallas as $destino => $texto)
                    @php($aqui = request()->routeIs(str_replace('.index', '.*', $destino)))
                    <a href="{{ route($destino) }}" @if ($aqui) aria-current="page" @endif
                       class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full px-2 text-sm font-medium {{ $aqui ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-ink-800 dark:text-paper-100 dark:ring-ink-500' }}">{{ $texto }}</a>
                @endforeach
            </nav>
        @endcan
        <x-rutas.avisos />
        @yield('content')
    </main>
</body>
</html>
