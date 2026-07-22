@php
    // Clases literales (JIT de Tailwind no interpola variables): mismo mapeo de colores
    // que usa el panel "Salud del sistema" (ok/advertencia/critico).
    $modoDteBadge = [
        'ok' => 'bg-green-100 text-green-700',
        'advertencia' => 'bg-amber-100 text-amber-700',
        'critico' => 'bg-rose-100 text-rose-700 animate-pulse',
    ];

    $usuario = auth()->user();
    $esAdmin = $usuario->hasRole('administrador');
    $veAuditoria = $usuario->hasAnyRole(['administrador', 'contador']);
    $veOperativos = $usuario->hasAnyRole(['administrador', 'contador', 'facturacion']); // PPQ y Exportaciones
    $esGestorDte = $usuario->hasAnyRole(['administrador', 'facturacion']); // acciones/prep de emisión
    $veClientes = $usuario->can('viewAny', App\Models\Cliente::class);
    $veProductos = $usuario->can('viewAny', App\Models\Producto::class);
    $veFacturacion = $usuario->can('viewAny', App\Models\Dte::class);

    // Activos por item (rutas actuales, sin cambios de lógica).
    $enInvalidaciones = request()->routeIs('facturacion.invalidaciones');
    $enPreparar = request()->routeIs('facturacion.preparar-produccion');
    $enReporteContadora = request()->routeIs('facturacion.reporte-contadora*');
    // "Facturación" cubre el listado y las pantallas de creación (CCF, NC, factura,
    // exportación), que ya no tienen enlace propio en el sidebar.
    $enCcfFacturas = request()->routeIs('facturacion.*') && ! $enInvalidaciones && ! $enPreparar && ! $enReporteContadora;

    $enNuevaLista = request()->routeIs('exportaciones.create');
    $enExpClientes = request()->routeIs('exportaciones.clientes.*');
    $enExpProductos = request()->routeIs('exportaciones.productos.*');
    $enListasEmpaque = request()->routeIs('exportaciones.*') && ! $enNuevaLista && ! $enExpClientes && ! $enExpProductos;
@endphp

{{-- Navegación: topbar fija (logo + usuario; los badges de modo DTE aparecen SOLO
     en pantallas de Facturación) y sidebar izquierda agrupada por secciones
     (off-canvas en móvil). Solo UX/layout: mismas rutas, mismos roles/permisos,
     sin lógica de negocio nueva. --}}
<div x-data="{ sidebarAbierta: false }">

    {{-- ===== Topbar ===== --}}
    <nav class="fixed inset-x-0 top-0 z-40 h-16 border-b border-gray-200 bg-white dark:border-ink-600 dark:bg-ink-900">
        <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
            {{-- Hamburguesa (solo móvil) --}}
            <button @click="sidebarAbierta = ! sidebarAbierta"
                    class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100 lg:hidden">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path x-show="! sidebarAbierta" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path x-show="sidebarAbierta" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2">
                <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-paper-100" />
                <span class="hidden text-sm font-semibold text-gray-800 dark:text-paper-100 sm:block">{{ config('app.name') }}</span>
            </a>

            {{-- Badges de modo DTE: SOLO en pantallas de Facturación/DTE (no en el resto
                 del sistema). No cambia ningún candado ni validación; es solo dónde se
                 muestra el aviso. Las vistas de facturación además llevan su propio
                 banner detallado (<x-modo-dte-aviso>), que no se toca. --}}
            @if ($modoDte && request()->routeIs('facturacion.*'))
                <div class="flex min-w-0 flex-wrap items-center gap-1.5 text-xs" title="{{ $modoDte['detalle'] }}">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold {{ $modoDteBadge[$modoDte['color']] ?? 'bg-gray-100 text-gray-600' }}">
                        MODO {{ $modoDte['etiqueta'] }}
                    </span>
                    @if (! empty($modoDte['modo_seguro']))
                        {{-- Refuerzo textual explícito: en modo seguro el sistema nuevo NO emite a producción. --}}
                        <span class="inline-flex items-center rounded-full bg-green-600 px-2 py-0.5 font-bold text-white">
                            NO EMITE PRODUCCIÓN
                        </span>
                    @endif
                    @if ($modoDte['mocks']['alguno'])
                        <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 font-semibold text-indigo-700"
                              title="Firma/transmisión/invalidación en modo MOCK: simulan el resultado sin usar credenciales ni transmitir de verdad.">
                            PRUEBAS / MOCK
                        </span>
                    @endif
                    <span class="hidden truncate text-gray-400 xl:inline">{{ $modoDte['detalle'] }}</span>
                </div>
            @endif

            {{-- Tema + Usuario / logout --}}
            <div class="ms-auto flex items-center gap-1">
                <x-theme-toggle />
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:bg-transparent dark:text-paper-300 dark:hover:text-paper-100">
                            <div>{{ $usuario->name }}</div>
                            <div class="ms-1">
                                <svg class="h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </nav>

    {{-- Fondo oscuro al abrir la sidebar en móvil --}}
    <div x-show="sidebarAbierta" x-cloak @click="sidebarAbierta = false"
         class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden"></div>

    {{-- ===== Sidebar ===== --}}
    <aside class="fixed bottom-0 left-0 top-16 z-30 w-64 -translate-x-full transform overflow-y-auto border-r border-gray-200 bg-white transition-transform duration-150 dark:border-ink-600 dark:bg-ink-900 lg:translate-x-0"
           :class="sidebarAbierta ? 'translate-x-0' : '-translate-x-full'">
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
                        <x-sidebar-link :href="route('facturacion.invalidaciones')" :active="$enInvalidaciones">Invalidar</x-sidebar-link>
                        @if ($esGestorDte)
                            <x-sidebar-link :href="route('facturacion.preparar-produccion')" :active="$enPreparar">Preparar emisión real</x-sidebar-link>
                        @endif
                    </div>
                </div>
            @endif

            @if ($veOperativos)
                <div>
                    <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="ppq" />Prontos Pagos</p>
                    <div class="space-y-0.5">
                        <x-sidebar-link :href="route('ppq.index')" :active="request()->routeIs('ppq.index', 'ppq.albaranes_por_fecha')">Buscar CCF / NC</x-sidebar-link>
                        <x-sidebar-link :href="route('ppq.lotes.index')" :active="request()->routeIs('ppq.lotes.*')">Historial PPQ</x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="contabilidad" />Contabilidad</p>
                    <div class="space-y-0.5">
                        {{-- Compras: CCF/facturas de proveedores recibidas por correo (con sus
                             filtros por estado dentro de la pantalla). Ventas: reporte de lo que
                             emitimos, que se le manda a la contadora. Solo navegación. --}}
                        <x-sidebar-link :href="route('documentos-recibidos.index')" :active="request()->routeIs('documentos-recibidos.*')">Compras</x-sidebar-link>
                        <x-sidebar-link :href="route('facturacion.reporte-contadora')" :active="$enReporteContadora">Ventas</x-sidebar-link>
                        <x-sidebar-link :href="route('contabilidad.paquete')" :active="request()->routeIs('contabilidad.paquete*')">Paquete mensual</x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="{{ $tituloGrupo }}"><x-sidebar-icon name="exportaciones" />Exportaciones</p>
                    <div class="space-y-0.5">
                        <x-sidebar-link :href="route('exportaciones.index')" :active="$enListasEmpaque">Listas de empaque</x-sidebar-link>
                        <x-sidebar-link :href="route('exportaciones.create')" :active="$enNuevaLista">Nueva lista de empaque</x-sidebar-link>
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
    </aside>
</div>
