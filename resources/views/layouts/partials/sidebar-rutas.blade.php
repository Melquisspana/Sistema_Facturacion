{{-- Sidebar del área Rutas (nombre técnico «rutas»: prefijo /rutas, permisos rutas.*,
     App\Enums\AreaSistema::Rutas). Deliberadamente SIN enlaces de Facturación ni de
     Planta. El grupo Seguimiento (Facturas por cobrar) tampoco: vive en la barra de Facturación (/cobros, /ppq) y el
     usuario pidió sacarlo de acá (28/10/2026); su módulo y sus permisos no cambian.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
<nav class="space-y-6 px-3 py-5">

    {{-- Menú de la oficina (11/11/2026), cada título en una línea: el Tablero primero (Resumen,
         Viajes, Mapa y Vendedores son sus pestañas), Pedidos, Ventas, Todos los viajes y Zonas.
         Al final, UNA sola entrada al celular del vendedor: adentro están sus pestañas (Inicio,
         Sacar pedido y Por entregar). Ya no hay «armar salida» ni «asignar salas». --}}
    <x-sidebar-group titulo="Rutas" icono="rutas">
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
        {{-- Abre la primera de las tres pantallas del celular que el usuario puede abrir: el
             Inicio para quien entrega; para la oficina, que solo mira, «Por entregar». Solo
             permisos: sin consultas (este menú se dibuja en toda el área). --}}
        @php
            $menuVendedor = match (true) {
                auth()->user()->can('rutas.entregas.registrar') => 'rutas.mi-salida.index',
                auth()->user()->can('rutas.ver') => 'rutas.por-entregar.index',
                auth()->user()->can('rutas.pedidos.registrar') => 'rutas.sacar-pedido.index',
                default => null,
            };
        @endphp
        @if ($menuVendedor)
            <x-sidebar-link :href="route($menuVendedor)" :active="request()->routeIs('rutas.mi-salida.*', 'rutas.sacar-pedido.*', 'rutas.por-entregar.*')">Menú del vendedor</x-sidebar-link>
        @endif
    </x-sidebar-group>

</nav>
