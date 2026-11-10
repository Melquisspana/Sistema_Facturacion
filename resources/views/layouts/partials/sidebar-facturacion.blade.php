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
                {{-- Órdenes de compra que llegaron por correo, para ver las que faltan facturar.
                     Mismo permiso que los documentos (dte.ver). --}}
                <x-sidebar-link :href="route('facturacion.ordenes-recibidas.index')" :active="request()->routeIs('facturacion.ordenes-recibidas.*')">Órdenes recibidas</x-sidebar-link>
            @endif
            @if ($veClientes)
                <x-sidebar-link :href="route('clientes.index')" :active="request()->routeIs('clientes.*')">Clientes</x-sidebar-link>
            @endif
            @if ($veProductos)
                {{-- UNA sola entrada «Productos». Nacionales es lo predeterminado y
                     exportación es la segunda pestaña de la misma pantalla, así que
                     `productos.*` la enciende entera —incluido productos.exportacion.*,
                     que cuelga de ese mismo prefijo de nombre. --}}
                <x-sidebar-link :href="route('productos.index')" :active="request()->routeIs('productos.*')">Productos</x-sidebar-link>
            @endif
            @if ($veExportaciones)
                {{-- La lista de empaque es el paso PREVIO a la factura de exportación, así
                     que vive junto a los documentos que alimenta y no en un área aparte. --}}
                <x-sidebar-link :href="route('facturacion.listas.index')" :active="$enListasEmpaque">Listas de empaque</x-sidebar-link>
            @endif
        </x-sidebar-group>
    @endif

    {{-- Seguimiento de facturas. El grupo contiene el ciclo de cobro de Calleja: seguimiento
         del CCF hasta el pago e historial de PPQ. /ppq y /cobros pertenecen
         técnicamente al área Facturación (el área se deriva de la URL, ver
         App\Enums\AreaSistema) y por eso se dibuja en este sidebar. No se movió
         ni un controlador ni un prefijo.

         SE LLAMABA «Cobros», LUEGO «Pronto pago» y después «Cobros de supermercados».
         El usuario pidió el 10/11/2026 que se llame «Seguimiento de facturas».
         Seguimiento de CCF e Historial de PPQ cuelgan directamente de él, sin un
         subtítulo que repita la misma idea.

         La barra del área Rutas ya no lo ofrece (28/10/2026): el módulo vive solo acá,
         con un solo nombre.

         La clave del colapsable sigue siendo «cobros» a propósito: es la llave de
         localStorage donde ya está guardado si cada usuario tiene el grupo abierto o
         cerrado, y renombrarla les reiniciaría esa preferencia sin ningún motivo. Es
         un identificador técnico, no un rótulo. --}}
    @if ($vePpq)
        <x-sidebar-group titulo="Seguimiento de facturas" icono="cobros" clave="cobros" :activo="$grupoCobrosActivo">
            {{-- «Buscar CCF / NC» ya no va en el menú: es un botón dentro del Seguimiento. --}}
            <x-sidebar-link :href="route('cobros.index')" :active="request()->routeIs('cobros.*', 'ppq.index', 'ppq.albaranes_por_fecha')">Seguimiento de CCF</x-sidebar-link>
            <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial de PPQ</x-sidebar-link>
        </x-sidebar-group>
    @endif

    {{-- Gastos ya NO vive acá: es un ÁREA propia (ver App\Enums\AreaSistema y
         layouts/partials/sidebar-gastos.blade.php). Se movió porque había crecido
         hasta tener su propio listado, pagos, informes y repeticiones: como grupo
         dentro de Facturación obligaba a elegir entre un menú largo o esconderle
         pantallas. Se llega por el selector de áreas de arriba. --}}

    @if ($veContabilidad)
        <x-sidebar-group titulo="Contabilidad" icono="contabilidad" clave="contabilidad" :activo="$grupoContabilidadActivo">
            {{-- Compras: CCF/facturas de proveedores recibidas por correo (con sus
                 filtros por estado dentro de la pantalla). Las ventas no tienen pantalla
                 propia desde el 06/11/2026 (no se usaba): siguen viajando en el paquete
                 mensual. Los nombres técnicos de las rutas son los históricos y no se
                 tocan: solo navegación. --}}
            @if ($veCompras)
                <x-sidebar-link :href="route('documentos-recibidos.index')" :active="request()->routeIs('documentos-recibidos.*')">Compras</x-sidebar-link>
            @endif
            @if ($veReportes)
                <x-sidebar-link :href="route('contabilidad.paquete')" :active="request()->routeIs('contabilidad.paquete*')">Paquete mensual</x-sidebar-link>
            @endif
        </x-sidebar-group>
    @endif

    {{-- El grupo «Exportaciones» se retiró: sus tres destinos se reubicaron y ninguno
         quedó sin puerta.

            Catálogo de productos → pestaña «De exportación» dentro de Productos.
            Clientes y precios    → bloque «Exportación» en la ficha del cliente.
            Listas de empaque     → fila propia en «Ventas y facturación», arriba.

         Las URL antiguas siguen funcionando y redirigen a su destino nuevo, así que un
         favorito guardado no acaba en un 404. Las rutas y los controladores anteriores
         no se borraron todavía: eso exige comprobar antes en producción que ya no les
         queda ningún consumidor. --}}


    {{-- Administración (Auditoría, Importaciones), Configuración y Sistema › Salud ya NO
         viven acá (04/11/2026). Configuración es un apartado propio, al que se entra
         desde la barra superior en cualquier área, y esos enlaces están en su índice
         (configuracion/_nav.blade.php), cada uno con el permiso de su ruta. Las URL y
         los permisos no cambiaron. Contabilidad, que revisa la auditoría sin
         administrar la configuración, la encuentra en el menú de su usuario. --}}
</nav>
