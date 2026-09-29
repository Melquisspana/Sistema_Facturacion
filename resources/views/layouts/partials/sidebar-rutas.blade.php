{{-- Sidebar del área Rutas (nombre técnico «rutas»: prefijo /rutas, permisos rutas.*,
     App\Enums\AreaSistema::Rutas). Deliberadamente SIN enlaces de Facturación ni de
     Planta.

     La única excepción es Cobros Calleja, cuyo seguimiento de CCF vive en Facturación
     (/cobros, /ppq): se dibuja acá porque las entregas y el cobro van de la mano; al
     entrar, la sidebar cambia sola a la de Facturación. No se movió ninguna ruta ni
     permiso.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
<nav class="space-y-6 px-3 py-5">

    {{-- Tres puertas y nada más (el usuario pidió simple, 27/09/2026): el día a día, lo
         que ya pasó y lo que se configura de vez en cuando. Asignación de salas y
         vendedores viven dentro de «Configurar rutas». --}}
    <x-sidebar-group titulo="Rutas" icono="rutas">
        <x-sidebar-link :href="route('rutas.dashboard')" :active="request()->routeIs('rutas.dashboard')">Rutas</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.salidas.index')" :active="request()->routeIs('rutas.salidas.*')">Salidas anteriores</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.rutas.index')" :active="request()->routeIs('rutas.rutas.*', 'rutas.asignacion.*', 'rutas.personal.*')">Configurar rutas</x-sidebar-link>
    </x-sidebar-group>

    {{-- Cobros Calleja: mismo permiso de siempre (ppq.ver). Se comprueba aparte del
         permiso del área porque son dos puertas distintas: el enlace no debe aparecer
         para quien no pueda entrar.

         El rótulo es el MISMO que en la barra de Facturación, y a propósito: es el
         mismo módulo visto desde otra área, y llamarlo de dos maneras distintas
         obligaba al usuario a deducir que hablaban de lo mismo. Los nombres técnicos
         —permiso ppq.ver, prefijo /ppq, clave rutas-ppq— no se tocan. --}}
    @can('ppq.ver')
        <x-sidebar-group titulo="Cobros Calleja" icono="ppq" clave="rutas-ppq" :activo="request()->routeIs('ppq.*', 'cobros.*')">
            <x-sidebar-link :href="route('cobros.index')" :active="request()->routeIs('cobros.*', 'ppq.index', 'ppq.albaranes_por_fecha')">Seguimiento de CCF</x-sidebar-link>
            <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial de PPQ</x-sidebar-link>
        </x-sidebar-group>
    @endcan
</nav>
