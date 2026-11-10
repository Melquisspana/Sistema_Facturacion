{{-- Sidebar del área Fábrica (App\Enums\AreaSistema::Fabrica): solo «Pedidos», lo que la
     fábrica manda a cada sala. Sin insumos, sin Rutas y sin Facturación. Sin contador: el
     menú no consulta tablas de pedidos (una tabla que falta no puede tumbar el sistema).

     Ocultar no autoriza: las rutas llevan `permission:fabrica.pedidos.mandar`. --}}
<nav class="space-y-6 px-3 py-5">
    <x-sidebar-group titulo="Fábrica" icono="salidas">
        @can('fabrica.pedidos.mandar')
            <x-sidebar-link :href="route('fabrica.pedidos.index')" :active="request()->routeIs('fabrica.pedidos.*')">Pedidos</x-sidebar-link>
        @endcan
    </x-sidebar-group>
</nav>
