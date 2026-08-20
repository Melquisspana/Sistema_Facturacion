{{-- Sidebar del área Facturación (que es «el resto del sistema»: todo lo que no
     es Producción ni Cobros). Reorganización VISUAL: mismas rutas, mismos
     permisos, mismos candados. Solo cambian el agrupamiento, algunos textos y el
     hecho de que los grupos grandes se pueden colapsar.

     Las variables de visibilidad ($veClientes, $veFacturacion, …) y las de grupo
     activo ($grupoVentasActivo, …) las calcula la vista padre y se heredan por
     @include. Ocultar NO autoriza: la protección real vive en el middleware de
     cada ruta, en las policies y en los controladores. --}}
<nav class="space-y-6 px-3 py-5">

    <x-sidebar-group titulo="Inicio" icono="inicio">
        <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-sidebar-link>
    </x-sidebar-group>

    {{-- Ventas y facturación: UN SOLO bloque. Comercial (con qué se factura) y
         Facturación (lo que se emite) son dos caras del mismo trabajo, y tenerlas
         como categorías hermanas obligaba a saber de antemano en cuál buscar. --}}
    @if ($veClientes || $veProductos || $veFacturacion)
        <x-sidebar-group titulo="Ventas y facturación" icono="facturacion" clave="ventas" :activo="$grupoVentasActivo">
            @if ($veClientes || $veProductos)
                <x-sidebar-subtitulo>Comercial</x-sidebar-subtitulo>
                @if ($veClientes)
                    <x-sidebar-link :href="route('clientes.index')" :active="request()->routeIs('clientes.*')">Clientes</x-sidebar-link>
                @endif
                @if ($veProductos)
                    <x-sidebar-link :href="route('productos.index')" :active="request()->routeIs('productos.*')">Productos</x-sidebar-link>
                @endif
            @endif

            @if ($veFacturacion)
                <x-sidebar-subtitulo>Facturación</x-sidebar-subtitulo>
                <x-sidebar-link :href="route('facturacion.index')" :active="$enDocumentosFiscales">Documentos fiscales</x-sidebar-link>
                @if ($vePreparacion)
                    <x-sidebar-link :href="route('facturacion.preparar-produccion')" :active="$enPreparar">Preparar emisión real</x-sidebar-link>
                @endif
            @endif
        </x-sidebar-group>
    @endif

    {{-- Cobros. PPQ vive acá por lo que ES —cobro de Calleja—, no por dónde está su
         ruta: /ppq pertenece técnicamente al área Facturación (el área se deriva de
         la URL, ver App\Enums\AreaSistema) y por eso se dibuja en este sidebar. El
         área Cobros presenta este mismo bloque junto a Salidas, Documentos por cobrar
         y Rutas. No se movió ni un controlador ni un prefijo. --}}
    @if ($vePpq)
        <x-sidebar-group titulo="Cobros" icono="cobros" clave="cobros" :activo="$grupoCobrosActivo">
            <x-sidebar-subtitulo>Prontos Pagos</x-sidebar-subtitulo>
            <x-sidebar-link :href="route('ppq.index')" :active="request()->routeIs('ppq.index', 'ppq.albaranes_por_fecha')">Buscar CCF / NC</x-sidebar-link>
            <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial PPQ</x-sidebar-link>
        </x-sidebar-group>
    @endif

    @if ($veContabilidad)
        <x-sidebar-group titulo="Contabilidad" icono="contabilidad" clave="contabilidad" :activo="$grupoContabilidadActivo">
            {{-- Compras: CCF/facturas de proveedores recibidas por correo (con sus
                 filtros por estado dentro de la pantalla). Ventas: reporte de lo que
                 emitimos, que se le manda a la contadora. Los nombres técnicos de las
                 rutas son los históricos y no se tocan: solo navegación. --}}
            @if ($veCompras)
                <x-sidebar-link :href="route('documentos-recibidos.index')" :active="request()->routeIs('documentos-recibidos.*')">Compras</x-sidebar-link>
            @endif
            @if ($veReportes)
                <x-sidebar-link :href="route('facturacion.reporte-contadora')" :active="$enReporteContadora">Ventas</x-sidebar-link>
                <x-sidebar-link :href="route('contabilidad.paquete')" :active="request()->routeIs('contabilidad.paquete*')">Paquete mensual</x-sidebar-link>
            @endif
        </x-sidebar-group>
    @endif

    @if ($veExportaciones)
        <x-sidebar-group titulo="Exportaciones" icono="exportaciones" clave="exportaciones" :activo="$grupoExportacionesActivo">
            {{-- «Nueva lista de empaque» ya no ocupa una fila permanente: crear es una
                 acción, no una sección, y el botón sigue estando en el listado y en el
                 dashboard para quien tiene exportaciones.gestionar. --}}
            <x-sidebar-link :href="route('exportaciones.index')" :active="$enListasEmpaque">Listas de empaque</x-sidebar-link>
            <x-sidebar-link :href="route('exportaciones.clientes.index')" :active="$enExpClientes">Clientes y precios</x-sidebar-link>
            <x-sidebar-link :href="route('exportaciones.productos.index')" :active="$enExpProductos">Catálogo de productos</x-sidebar-link>
        </x-sidebar-group>
    @endif

    {{-- Administración: gente y rastro (quién entra, qué hizo, qué se cargó). Las
         herramientas técnicas de infraestructura viven aparte, en «Sistema». --}}
    @if ($esAdmin || $veAuditoria)
        <x-sidebar-group titulo="Administración" icono="usuarios" clave="administracion" :activo="$grupoAdministracionActivo">
            @if ($esAdmin)
                <x-sidebar-link :href="route('usuarios.index')" :active="request()->routeIs('usuarios.*')">Usuarios</x-sidebar-link>
            @endif
            @if ($veAuditoria)
                <x-sidebar-link :href="route('auditoria.index')" :active="request()->routeIs('auditoria.*')">Auditoría</x-sidebar-link>
            @endif
            @if ($esAdmin)
                <x-sidebar-link :href="route('importaciones.index')" :active="request()->routeIs('importaciones.*')">Importaciones</x-sidebar-link>
            @endif
        </x-sidebar-group>
    @endif

    {{-- Configuración: el marco fiscal y operativo del sistema, separado de
         Administración a propósito. Son las mismas cinco pestañas de la pantalla de
         configuración más «Correo», que existía como pantalla pero no estaba
         enlazada en ningún lado. Misma condición de visibilidad de siempre
         ($esAdmin) y mismo permiso de ruta (configuracion.gestionar). --}}
    @if ($esAdmin)
        <x-sidebar-group titulo="Configuración" icono="configuracion" clave="configuracion" :activo="$grupoConfiguracionActivo">
            <x-sidebar-link :href="route('configuracion.empresa.edit')" :active="request()->routeIs('configuracion.empresa.*')">Empresa emisora</x-sidebar-link>
            <x-sidebar-link :href="route('configuracion.establecimientos.index')" :active="request()->routeIs('configuracion.establecimientos.*')">Establecimientos</x-sidebar-link>
            <x-sidebar-link :href="route('configuracion.puntos-venta.index')" :active="request()->routeIs('configuracion.puntos-venta.*')">Puntos de venta</x-sidebar-link>
            <x-sidebar-link :href="route('configuracion.correlativos.index')" :active="request()->routeIs('configuracion.correlativos.*')">Correlativos</x-sidebar-link>
            <x-sidebar-link :href="route('configuracion.contabilidad.edit')" :active="request()->routeIs('configuracion.contabilidad.*')">Contabilidad</x-sidebar-link>
            <x-sidebar-link :href="route('configuracion.correo.edit')" :active="request()->routeIs('configuracion.correo.*')">Correo</x-sidebar-link>
        </x-sidebar-group>
    @endif

    {{-- Sistema: infraestructura (colas, worker, modo de transmisión). Va al final y
         separado por una línea porque no es una opción de trabajo diario ni de
         administración de negocio: se entra cuando algo falla. Misma ruta y mismo
         permiso de siempre. --}}
    @if ($esAdmin)
        <div class="border-t border-gray-200 pt-5 dark:border-ink-600">
            <x-sidebar-group titulo="Sistema" icono="sistema">
                <x-sidebar-link :href="route('admin.salud-sistema')" :active="request()->routeIs('admin.salud-sistema')">
                    <span>Salud del sistema</span>
                    @if (($jobsFallidos ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700"
                              title="{{ $jobsFallidos }} trabajos en cola fallidos (correos/DTE). Revisá Salud del sistema.">
                            {{ $jobsFallidos }}
                        </span>
                    @endif
                </x-sidebar-link>
            </x-sidebar-group>
        </div>
    @endif
</nav>
