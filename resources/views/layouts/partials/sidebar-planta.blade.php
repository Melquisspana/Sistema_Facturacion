{{-- Sidebar del área Producción (planta). Deliberadamente NO contiene ningún
     enlace del área Facturación: quien trabaja en planta no navega documentos
     fiscales, clientes, PPQ ni exportaciones. Se irán agregando entradas a medida
     que existan rutas reales; nunca enlaces rotos. --}}
@php
    // Mismo estilo de título de grupo que el sidebar de Facturación.
    $tituloGrupo = 'mb-1.5 flex items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
@endphp
<nav class="space-y-6 px-3 py-5">

    <div>
        <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="planta" />Producción</p>
        <div class="space-y-0.5">
            <x-sidebar-link :href="route('planta.dashboard')" :active="request()->routeIs('planta.dashboard')">Inicio</x-sidebar-link>
        </div>
    </div>

    {{-- Operación diaria. Aparece antes que los catálogos porque es lo que se
         usa todos los días; los catálogos se tocan de vez en cuando.

         Cada entrada lleva SU permiso, no el del grupo: se lee lo que se puede
         entrar a ver. Ocultar no autoriza —las rutas llevan su middleware—, pero
         un enlace que siempre da 403 es ruido. --}}
    @canany(['planta.recepciones.ver', 'planta.traslados.ver', 'planta.existencias.ver', 'planta.ajustes.ver', 'planta.movimientos.ver'])
        <div>
            <p class="{{ $tituloGrupo }}">Operación</p>
            <div class="space-y-0.5">
                {{-- Existencias abre el grupo porque es la pregunta que más se hace:
                     qué hay y dónde. Movimientos lo cierra porque es la consulta de
                     respaldo, la que se abre cuando el saldo no cuadra con lo que
                     se esperaba. --}}
                @can('planta.existencias.ver')
                    <x-sidebar-link :href="route('planta.existencias.index')" :active="request()->routeIs('planta.existencias.*')">Existencias</x-sidebar-link>
                @endcan
                @can('planta.recepciones.ver')
                    <x-sidebar-link :href="route('planta.recepciones.index')" :active="request()->routeIs('planta.recepciones.*')">Recepciones</x-sidebar-link>
                @endcan
                @can('planta.traslados.ver')
                    <x-sidebar-link :href="route('planta.traslados.index')" :active="request()->routeIs('planta.traslados.*')">Traslados</x-sidebar-link>
                @endcan
                @can('planta.existencias.ver')
                    <x-sidebar-link :href="route('planta.disponibilidad.index')" :active="request()->routeIs('planta.disponibilidad.*')">Disponibilidad</x-sidebar-link>
                @endcan
                @can('planta.ajustes.ver')
                    <x-sidebar-link :href="route('planta.ajustes.index')" :active="request()->routeIs('planta.ajustes.*')">Ajustes</x-sidebar-link>
                @endcan
                @can('planta.movimientos.ver')
                    <x-sidebar-link :href="route('planta.movimientos.index')" :active="request()->routeIs('planta.movimientos.*')">Movimientos</x-sidebar-link>
                @endcan
            </div>
        </div>
    @endcanany

    {{-- Catálogos base. El grupo entero desaparece sin planta.catalogos.ver,
         igual que hace sidebar-facturacion. Ocultar no autoriza: las rutas
         llevan su propio middleware.

         Lotes va aquí aunque no se cree a mano —nace al confirmar una
         recepción—: la pregunta que responde, «qué es este lote y de dónde
         salió», es de catálogo y no de operación. Por eso es el único que no
         ofrece «nuevo». --}}
    @can('planta.catalogos.ver')
        <div>
            <p class="{{ $tituloGrupo }}">Catálogos</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('planta.insumos.index')" :active="request()->routeIs('planta.insumos.*')">Insumos</x-sidebar-link>
                <x-sidebar-link :href="route('planta.lotes.index')" :active="request()->routeIs('planta.lotes.*')">Lotes</x-sidebar-link>
                <x-sidebar-link :href="route('planta.proveedores.index')" :active="request()->routeIs('planta.proveedores.*')">Proveedores</x-sidebar-link>
                <x-sidebar-link :href="route('planta.ubicaciones.index')" :active="request()->routeIs('planta.ubicaciones.*')">Ubicaciones</x-sidebar-link>
                <x-sidebar-link :href="route('planta.productos-base.index')" :active="request()->routeIs('planta.productos-base.*')">Productos</x-sidebar-link>
                <x-sidebar-link :href="route('planta.presentaciones.index')" :active="request()->routeIs('planta.presentaciones.*')">Presentaciones</x-sidebar-link>
                <x-sidebar-link :href="route('planta.empaques.index')" :active="request()->routeIs('planta.empaques.*')">Configuraciones de empaque</x-sidebar-link>
            </div>
        </div>
    @endcan

</nav>
