{{-- Sidebar del área Cobros (nombre técnico «rutas»: prefijo /rutas-cobros,
     permisos rutas.*, App\Enums\AreaSistema::Rutas). Deliberadamente SIN enlaces
     de Facturación ni de Planta: quien trabaja cobros no navega documentos
     fiscales desde acá.

     La única excepción es Pronto pago, que ES cobro aunque su ruta viva bajo
     /ppq (área Facturación). Se dibuja acá porque es donde el usuario lo busca;
     al entrar, la sidebar cambia sola a la de Facturación, que presenta el mismo
     bloque con el mismo rótulo. No se movió ninguna ruta ni permiso.

     Ocultar no autoriza: cada grupo de rutas lleva su propio middleware. --}}
<nav class="space-y-6 px-3 py-5">

    <x-sidebar-group titulo="Cobros" icono="rutas">
        <x-sidebar-link :href="route('rutas.dashboard')" :active="request()->routeIs('rutas.dashboard')">Resumen</x-sidebar-link>
    </x-sidebar-group>

    {{-- Operación. Salidas va primero porque es lo que se mira todos los días; el
         catálogo de rutas se toca de vez en cuando. La bandeja cruza todas las
         salidas: es donde se contesta «qué me falta cobrar» sin abrir viaje por
         viaje, y por eso va pegada a Salidas. --}}
    <x-sidebar-group titulo="Operación" icono="operacion" clave="rutas-operacion"
                     :activo="request()->routeIs('rutas.salidas.*', 'rutas.documentos.*', 'rutas.rutas.*')">
        <x-sidebar-link :href="route('rutas.salidas.index')" :active="request()->routeIs('rutas.salidas.*')">Salidas</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.documentos.index')" :active="request()->routeIs('rutas.documentos.*')">Documentos por cobrar</x-sidebar-link>
        <x-sidebar-link :href="route('rutas.rutas.index')" :active="request()->routeIs('rutas.rutas.*')">Rutas</x-sidebar-link>
    </x-sidebar-group>

    {{-- Pronto pago: mismo permiso de siempre (ppq.ver). Se comprueba aparte del
         permiso del área porque son dos puertas distintas: hoy solo el administrador
         tiene rutas.ver y también ppq.ver, pero el enlace no debe aparecer para
         quien no pueda entrar.

         El rótulo es el MISMO que en la barra de Facturación, y a propósito: es el
         mismo módulo visto desde otra área, y llamarlo de dos maneras distintas
         obligaba al usuario a deducir que hablaban de lo mismo. Los nombres técnicos
         —permiso ppq.ver, prefijo /ppq, clave rutas-ppq— no se tocan. --}}
    @can('ppq.ver')
        <x-sidebar-group titulo="Pronto pago" icono="ppq" clave="rutas-ppq" :activo="request()->routeIs('ppq.*')">
            <x-sidebar-link :href="route('ppq.index')" :active="request()->routeIs('ppq.index', 'ppq.albaranes_por_fecha')">Buscar CCF / NC</x-sidebar-link>
            <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial PPQ</x-sidebar-link>
        </x-sidebar-group>
    @endcan
</nav>
