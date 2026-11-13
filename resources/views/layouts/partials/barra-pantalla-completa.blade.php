{{-- Barra de arriba a pantalla completa (la fábrica, 12/11/2026): sin barra lateral, sin
     selector de áreas y sin Configuración. Solo la empresa, quién está y «Salir». Quien ve
     más del sistema que Fábrica (el Administrador) tiene además «Volver al sistema»; la
     tableta de la fábrica no lo ve. Ocultar no autoriza: cada ruta lleva su permiso.
     Mismas clases que la barra del sistema (layouts/navigation), con su modo oscuro. --}}
@php($usuario = auth()->user())
<header class="border-b border-gray-200 bg-white dark:border-ink-600 dark:bg-ink-900">
    <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
        <span class="flex min-w-0 items-center gap-2">
            <x-application-logo class="block h-9 w-auto" />
            <span class="truncate text-sm font-semibold text-gray-800 dark:text-paper-100">{{ \App\Support\NombreEmpresa::actual() }}</span>
        </span>
        <div class="ms-auto flex flex-wrap items-center gap-1">
            <span class="px-2 text-sm text-gray-600 dark:text-paper-300">{{ $usuario->name }}</span>
            @unless (\App\Enums\AreaSistema::soloFabrica($usuario))
                <a href="{{ route('dashboard') }}"
                   class="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-gray-500 underline hover:text-gray-700 dark:text-paper-300 dark:hover:text-paper-100">Volver al sistema</a>
            @endunless
            <x-theme-toggle />
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="inline-flex min-h-11 items-center rounded-md px-4 text-base font-semibold text-gray-700 underline hover:text-gray-900 dark:text-paper-100 dark:hover:text-paper-100">Salir</button>
            </form>
        </div>
    </div>
</header>
