{{-- El marco de «Clientes» para la oficina (con la barra lateral): el encabezado de la pantalla
     (@section('encabezado')) arriba, y los avisos y el contenido al ancho de siempre. El vendedor,
     que entra sin `rutas.ver` (28/11/2026), ve las mismas pantallas en su layout del teléfono
     (layouts.vendedor), con su menú de pastillas; ahí el encabezado lo pone cada vista. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            @yield('encabezado')
        </div>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <x-rutas.avisos />
            @yield('content')
        </div>
    </div>
</x-app-layout>
