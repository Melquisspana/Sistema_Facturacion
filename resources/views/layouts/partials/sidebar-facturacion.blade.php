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

    {{-- Pronto pago. PPQ vive acá por lo que ES —el cobro de Calleja—, no por dónde
         está su ruta: /ppq pertenece técnicamente al área Facturación (el área se
         deriva de la URL, ver App\Enums\AreaSistema) y por eso se dibuja en este
         sidebar. No se movió ni un controlador ni un prefijo.

         SE LLAMABA «Cobros» Y DENTRO DECÍA «Prontos Pagos». Dos rótulos para dos
         filas: el de fuera prometía más de lo que hay —cobros a secas es todo el
         ciclo, y acá sólo está el pronto pago— y el de dentro repetía la misma idea
         un escalón más abajo. Ahora el grupo se llama por lo que contiene y las dos
         opciones cuelgan directamente de él.

         «Cobros» sigue siendo el nombre del ÁREA (AreaSistema::label), que es otra
         cosa y no se toca: ahí sí caben Salidas, Documentos por cobrar y Rutas junto
         a este bloque. La barra de esa área usa el MISMO rótulo «Pronto pago» para el
         mismo módulo, para que no se llame de dos maneras según por dónde entres.

         La clave del colapsable sigue siendo «cobros» a propósito: es la llave de
         localStorage donde ya está guardado si cada usuario tiene el grupo abierto o
         cerrado, y renombrarla les reiniciaría esa preferencia sin ningún motivo. Es
         un identificador técnico, no un rótulo. --}}
    @if ($vePpq)
        <x-sidebar-group titulo="Pronto pago" icono="cobros" clave="cobros" :activo="$grupoCobrosActivo">
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

    {{-- El grupo «Exportaciones» se retiró: sus tres destinos se reubicaron y ninguno
         quedó sin puerta.

            Catálogo de productos → pestaña «De exportación» dentro de Productos.
            Clientes y precios    → bloque «Exportación» en la ficha del cliente.
            Listas de empaque     → fila propia en «Ventas y facturación», arriba.

         Las URL antiguas siguen funcionando y redirigen a su destino nuevo, así que un
         favorito guardado no acaba en un 404. Las rutas y los controladores anteriores
         no se borraron todavía: eso exige comprobar antes en producción que ya no les
         queda ningún consumidor. --}}


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
