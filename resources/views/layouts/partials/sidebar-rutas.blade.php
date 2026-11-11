{{-- Sidebar del área Rutas (nombre técnico «rutas»: prefijo /rutas, permisos rutas.*,
     App\Enums\AreaSistema::Rutas). Deliberadamente SIN enlaces de Facturación ni de
     Planta. El grupo Seguimiento (Facturas por cobrar) tampoco: vive en la barra de Facturación (/cobros, /ppq) y el
     usuario pidió sacarlo de acá (28/10/2026); su módulo y sus permisos no cambian.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
<nav class="space-y-6 px-3 py-5">

    {{-- Menú corto del flujo único (10/11/2026), cada título en una línea. Primero lo del
         vendedor, para quien también sale a vender (el jefe): Inicio, Sacar pedido y Por
         entregar. Después lo de la oficina: el Tablero (Resumen, Viajes, Mapa y Vendedores
         son sus pestañas), Pedidos, Ventas, Todos los viajes y Zonas. Ya no
         hay «armar salida» ni «asignar salas». --}}
    <x-sidebar-group titulo="Rutas" icono="rutas">
        @can('rutas.entregas.registrar')
            <x-sidebar-link :href="route('rutas.mi-salida.index')" :active="request()->routeIs('rutas.mi-salida.*')">Inicio</x-sidebar-link>
        @endcan
        @can('rutas.pedidos.registrar')
            <x-sidebar-link :href="route('rutas.sacar-pedido.index')" :active="request()->routeIs('rutas.sacar-pedido.*')">Sacar pedido</x-sidebar-link>
        @endcan
        <x-sidebar-link :href="route('rutas.por-entregar.index')" :active="request()->routeIs('rutas.por-entregar.*')">Por entregar</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.dashboard')" :active="request()->routeIs('rutas.dashboard', 'rutas.viajes', 'rutas.mapa')">Tablero</x-sidebar-link>
        @can('rutas.pedidos.ver')
            <x-sidebar-link :href="route('rutas.pedidos.index')" :active="request()->routeIs('rutas.pedidos.*')">Pedidos</x-sidebar-link>
        @endcan
        @can('rutas.ventas.ver')
            <x-sidebar-link :href="route('rutas.ventas.index')" :active="request()->routeIs('rutas.ventas.*')">Ventas</x-sidebar-link>
        @endcan
        <x-sidebar-link :href="route('rutas.salidas.index')" :active="request()->routeIs('rutas.salidas.*')">Todos los viajes</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.rutas.index')" :active="request()->routeIs('rutas.rutas.*')">Zonas</x-sidebar-link>
        {{-- Los empleados son solo de la administración (`planilla.ver`, 04/11/2026). --}}
        @if (auth()->user()->can('rutas.personal.ver') && auth()->user()->can('planilla.ver'))
            @unless (\App\Models\PersonalRuta::seGestionaEnPersonal(auth()->user()))
                <x-sidebar-link :href="route('rutas.personal.index')" :active="request()->routeIs('rutas.personal.*')">Personal</x-sidebar-link>
            @endunless
        @endif
    </x-sidebar-group>

</nav>
