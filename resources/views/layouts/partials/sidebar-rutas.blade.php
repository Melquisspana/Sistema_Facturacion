{{-- Sidebar del área Rutas (nombre técnico «rutas»: prefijo /rutas, permisos rutas.*,
     App\Enums\AreaSistema::Rutas). Deliberadamente SIN enlaces de Facturación ni de
     Planta. Cobros Calleja tampoco: vive en la barra de Facturación (/cobros, /ppq) y el
     usuario pidió sacarlo de acá (28/10/2026); su módulo y sus permisos no cambian.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
<nav class="space-y-6 px-3 py-5">

    {{-- Tres puertas y nada más (el usuario pidió simple, 27/09/2026): el día a día, lo
         que ya pasó y lo que se configura de vez en cuando. Asignación de salas y
         vendedores viven dentro de «Configurar rutas». --}}
    <x-sidebar-group titulo="Rutas" icono="rutas">
        <x-sidebar-link :href="route('rutas.dashboard')" :active="request()->routeIs('rutas.dashboard')">Rutas</x-sidebar-link>
        @can('rutas.ventas.ver')
            <x-sidebar-link :href="route('rutas.ventas.index')" :active="request()->routeIs('rutas.ventas.*')">Ventas</x-sidebar-link>
        @endcan
        <x-sidebar-link :href="route('rutas.salidas.index')" :active="request()->routeIs('rutas.salidas.*')">Salidas anteriores</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.rutas.index')" :active="request()->routeIs('rutas.rutas.*', 'rutas.asignacion.*', 'rutas.personal.*')">Configurar rutas</x-sidebar-link>
    </x-sidebar-group>

</nav>
