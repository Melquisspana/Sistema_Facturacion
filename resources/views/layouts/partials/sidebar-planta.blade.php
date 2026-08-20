{{-- Sidebar del área Producción (planta). Deliberadamente NO contiene ningún
     enlace del área Facturación ni de Cobros: quien trabaja en planta no navega
     documentos fiscales, clientes, PPQ ni exportaciones. Se irán agregando
     entradas a medida que existan rutas reales; nunca enlaces rotos.

     Reorganización VISUAL: mismos permisos, mismas rutas, mismo orden. Solo se
     ajustaron textos («Inicio» → «Resumen», «Productos» → «Productos base») y los
     grupos grandes ahora se pueden colapsar. --}}
<nav class="space-y-6 px-3 py-5">

    <x-sidebar-group titulo="Producción" icono="planta">
        <x-sidebar-link :href="route('planta.dashboard')" :active="request()->routeIs('planta.dashboard')">Resumen</x-sidebar-link>
    </x-sidebar-group>

    {{-- Operación diaria. Aparece antes que los catálogos porque es lo que se
         usa todos los días; los catálogos se tocan de vez en cuando.

         Cada entrada lleva SU permiso, no el del grupo: se lee lo que se puede
         entrar a ver. Ocultar no autoriza —las rutas llevan su middleware—, pero
         un enlace que siempre da 403 es ruido. --}}
    @canany(['planta.recepciones.ver', 'planta.traslados.ver', 'planta.existencias.ver', 'planta.ajustes.ver', 'planta.movimientos.ver'])
        <x-sidebar-group titulo="Operación" icono="operacion" clave="planta-operacion"
                         :activo="request()->routeIs('planta.existencias.*', 'planta.recepciones.*', 'planta.traslados.*', 'planta.disponibilidad.*', 'planta.ajustes.*', 'planta.movimientos.*')">
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
        </x-sidebar-group>
    @endcanany

    {{-- Catálogos base. El grupo entero desaparece sin planta.catalogos.ver,
         igual que hace sidebar-facturacion. Ocultar no autoriza: las rutas
         llevan su propio middleware.

         Lotes va aquí aunque no se cree a mano —nace al confirmar una
         recepción—: la pregunta que responde, «qué es este lote y de dónde
         salió», es de catálogo y no de operación. Por eso es el único que no
         ofrece «nuevo». --}}
    @can('planta.catalogos.ver')
        <x-sidebar-group titulo="Catálogos" icono="catalogos" clave="planta-catalogos"
                         :activo="request()->routeIs('planta.insumos.*', 'planta.lotes.*', 'planta.proveedores.*', 'planta.ubicaciones.*', 'planta.productos-base.*', 'planta.presentaciones.*', 'planta.empaques.*')">
            <x-sidebar-link :href="route('planta.insumos.index')" :active="request()->routeIs('planta.insumos.*')">Insumos</x-sidebar-link>
            <x-sidebar-link :href="route('planta.lotes.index')" :active="request()->routeIs('planta.lotes.*')">Lotes</x-sidebar-link>
            <x-sidebar-link :href="route('planta.proveedores.index')" :active="request()->routeIs('planta.proveedores.*')">Proveedores</x-sidebar-link>
            <x-sidebar-link :href="route('planta.ubicaciones.index')" :active="request()->routeIs('planta.ubicaciones.*')">Ubicaciones</x-sidebar-link>
            <x-sidebar-link :href="route('planta.productos-base.index')" :active="request()->routeIs('planta.productos-base.*')">Productos base</x-sidebar-link>
            <x-sidebar-link :href="route('planta.presentaciones.index')" :active="request()->routeIs('planta.presentaciones.*')">Presentaciones</x-sidebar-link>
            <x-sidebar-link :href="route('planta.empaques.index')" :active="request()->routeIs('planta.empaques.*')">Configuración de empaque</x-sidebar-link>
        </x-sidebar-group>
    @endcan
</nav>
