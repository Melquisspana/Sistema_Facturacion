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

    {{-- Ventas y facturación: UN SOLO bloque y una sola lista.

         Antes iba partido en dos sub-bloques, «Comercial» (clientes y productos) y
         «Facturación» (los documentos). La división pedía saber de antemano en cuál
         de las dos buscar, y el grupo ya se llama «Ventas y facturación»: dentro
         volvía a poner «Facturación», que no distingue nada. Facturar un CCF empieza
         indistintamente por el cliente, por el producto o por el documento, así que
         los tres son hermanos y no dos familias.

         El documento va PRIMERO porque es lo que se abre todos los días; los
         catálogos se tocan cuando algo cambia.

         «Preparar emisión real» ya no está acá: era el guion de la primera emisión
         real, no una herramienta de trabajo diario. La ruta, el permiso
         (preparacion.ver) y la pantalla siguen intactos —se llega por URL—, y sus
         diagnósticos permanentes viven en Configuración y en Salud del sistema.
         Crear e invalidar tampoco tienen fila: son acciones del listado y de la
         ficha, no categorías. --}}
    @if ($veFacturacion || $veClientes || $veProductos)
        <x-sidebar-group titulo="Ventas y facturación" icono="facturacion" clave="ventas" :activo="$grupoVentasActivo">
            @if ($veFacturacion)
                <x-sidebar-link :href="route('facturacion.index')" :active="$enDocumentosFiscales">Documentos fiscales</x-sidebar-link>
            @endif
            @if ($veClientes)
                <x-sidebar-link :href="route('clientes.index')" :active="request()->routeIs('clientes.*')">Clientes</x-sidebar-link>
            @endif
            @if ($veProductos)
                <x-sidebar-link :href="route('productos.index')" :active="request()->routeIs('productos.*')">Productos</x-sidebar-link>
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

    {{-- Configuración: UNA entrada directa, sin grupo que la envuelva.

         Antes había seis filas sueltas —empresa, establecimientos, puntos de venta,
         correlativos, contabilidad y correo—, elegidas en su día porque eran las
         pantallas que existían. Hoy el Centro de Configuración tiene CATORCE, y las
         ocho restantes (Hacienda / API, certificado y firmador, parámetros fiscales,
         invalidación, las dos integraciones, respaldos y estado, y el propio resumen)
         no tenían ninguna entrada: para llegar a «Hacienda / API» había que entrar
         antes a «Empresa emisora» y descubrir el índice de al lado.

         Seis atajos que además escondían la mitad del módulo valen menos que una
         puerta que lo muestra entera. El índice agrupado ya existe y es la fuente
         única (configuracion/_nav.blade.php, vía <x-configuracion-layout>): esta fila
         lleva a su portada y desde ahí las catorce están a un clic.

         NO ES UN <x-sidebar-group>. Un grupo existe para agrupar, y con una sola
         opción el encabezado sólo conseguía decir «Configuración» dos veces
         seguidas: una en el rótulo de la sección y otra en la fila de debajo. El
         icono se pone acá dentro para que la entrada conserve el peso visual de una
         sección sin fingir que contiene algo.

         Misma condición de visibilidad de siempre ($esAdmin) y mismo permiso de ruta
         (configuracion.gestionar). No se retiró ninguna pantalla ni ninguna ruta. --}}
    @if ($esAdmin)
        <x-sidebar-link :href="route('configuracion.resumen')" :active="$enConfiguracion">
            <span class="flex min-w-0 items-center gap-1.5">
                <x-sidebar-icon name="configuracion" />
                <span class="truncate">Configuración</span>
            </span>
        </x-sidebar-link>
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
