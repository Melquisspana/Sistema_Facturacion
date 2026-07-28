{{-- Sidebar del área Facturación: EXTRACCIÓN LITERAL del cuerpo que vivía en
     layouts/navigation.blade.php. Mismas rutas, mismos permisos, mismos textos y
     mismo orden — no se cambió ni una etiqueta al moverlo. Las variables de
     visibilidad ($veClientes, $veFacturacion, …) las calcula la vista padre y se
     heredan por @include. --}}
@php
    // Título de grupo uniforme: letra pequeña, mayúsculas espaciadas, contraste
    // deliberadamente MENOR que el de un enlace (jerarquía: la categoría orienta,
    // el enlace es lo que se clickea).
    $tituloGrupo = 'mb-1.5 flex items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
@endphp
<nav class="space-y-6 px-3 py-5">

    <div>
        <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="inicio" />Inicio</p>
        <div class="space-y-0.5">
            <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Dashboard</x-sidebar-link>
        </div>
    </div>

    @if ($veClientes || $veProductos)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="comercial" />Comercial</p>
            <div class="space-y-0.5">
                @if ($veClientes)
                    <x-sidebar-link :href="route('clientes.index')" :active="request()->routeIs('clientes.*')">Clientes de facturación</x-sidebar-link>
                @endif
                @if ($veProductos)
                    <x-sidebar-link :href="route('productos.index')" :active="request()->routeIs('productos.*')">Productos</x-sidebar-link>
                @endif
            </div>
        </div>
    @endif

    @if ($veFacturacion)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="facturacion" />Facturación</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('facturacion.index')" :active="$enCcfFacturas">Facturación</x-sidebar-link>
                @if ($vePreparacion)
                    <x-sidebar-link :href="route('facturacion.preparar-produccion')" :active="$enPreparar">Preparar emisión real</x-sidebar-link>
                @endif
            </div>
        </div>
    @endif

    @if ($vePpq)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="ppq" />Prontos Pagos</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('ppq.index')" :active="request()->routeIs('ppq.index', 'ppq.albaranes_por_fecha')">Buscar CCF / NC</x-sidebar-link>
                <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial PPQ</x-sidebar-link>
            </div>
        </div>
    @endif

    @if ($veContabilidad)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="contabilidad" />Contabilidad</p>
            <div class="space-y-0.5">
                {{-- Compras: CCF/facturas de proveedores recibidas por correo (con sus
                     filtros por estado dentro de la pantalla). Ventas: reporte de lo que
                     emitimos, que se le manda a la contadora. Solo navegación. --}}
                @if ($veCompras)
                    <x-sidebar-link :href="route('documentos-recibidos.index')" :active="request()->routeIs('documentos-recibidos.*')">Compras</x-sidebar-link>
                @endif
                @if ($veReportes)
                    <x-sidebar-link :href="route('facturacion.reporte-contadora')" :active="$enReporteContadora">Ventas</x-sidebar-link>
                    <x-sidebar-link :href="route('contabilidad.paquete')" :active="request()->routeIs('contabilidad.paquete*')">Paquete mensual</x-sidebar-link>
                @endif
            </div>
        </div>
    @endif

    @if ($veExportaciones)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="exportaciones" />Exportaciones</p>
            <div class="space-y-0.5">
                <x-sidebar-link :href="route('exportaciones.index')" :active="$enListasEmpaque">Listas de empaque</x-sidebar-link>
                @if ($puedeGestionarExportaciones)
                    <x-sidebar-link :href="route('exportaciones.create')" :active="$enNuevaLista">Nueva lista de empaque</x-sidebar-link>
                @endif
                <x-sidebar-link :href="route('exportaciones.clientes.index')" :active="$enExpClientes">Perfiles y precios de exportación</x-sidebar-link>
                <x-sidebar-link :href="route('exportaciones.productos.index')" :active="$enExpProductos">Catálogo de productos</x-sidebar-link>
            </div>
        </div>
    @endif

    @if ($esAdmin || $veAuditoria)
        <div>
            <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="admin" />Administración</p>
            <div class="space-y-0.5">
                @if ($esAdmin)
                    <x-sidebar-link :href="route('configuracion.empresa.edit')" :active="request()->routeIs('configuracion.*')">Configuración</x-sidebar-link>
                    <x-sidebar-link :href="route('usuarios.index')" :active="request()->routeIs('usuarios.*')">Usuarios</x-sidebar-link>
                    <x-sidebar-link :href="route('importaciones.index')" :active="request()->routeIs('importaciones.*')">Importaciones</x-sidebar-link>
                    <x-sidebar-link :href="route('admin.salud-sistema')" :active="request()->routeIs('admin.salud-sistema')">
                        <span>Salud del sistema</span>
                        @if (($jobsFallidos ?? 0) > 0)
                            <span class="inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700"
                                  title="{{ $jobsFallidos }} trabajos en cola fallidos (correos/DTE). Revisá Salud del sistema.">
                                {{ $jobsFallidos }}
                            </span>
                        @endif
                    </x-sidebar-link>
                @endif
                @if ($veAuditoria)
                    <x-sidebar-link :href="route('auditoria.index')" :active="request()->routeIs('auditoria.*')">Auditoría</x-sidebar-link>
                @endif
            </div>
        </div>
    @endif

    {{-- Futuro (Inventario, Documentos recibidos, Reportes): agregar aquí su
         sección cuando existan rutas reales; no se muestran enlaces rotos. --}}
</nav>
