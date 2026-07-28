@php
    // Clases literales (JIT de Tailwind no interpola variables): mismo mapeo de colores
    // que usa el panel "Salud del sistema" (ok/advertencia/critico).
    $modoDteBadge = [
        'ok' => 'bg-green-100 text-green-700',
        'advertencia' => 'bg-amber-100 text-amber-700',
        'critico' => 'bg-rose-100 text-rose-700 animate-pulse',
    ];

    // Visibilidad por PERMISO (no por rol): los botones/enlaces se ocultan según lo
    // que cada usuario puede hacer. La protección real vive en backend (policies +
    // middleware permission:); esto es solo complemento visual.
    $usuario = auth()->user();
    $esAdmin = $usuario->hasRole('administrador');
    $veAuditoria = $usuario->can('auditoria.ver');
    $vePpq = $usuario->can('ppq.ver');
    $puedeGestionarPpq = $usuario->can('ppq.gestionar');
    $veExportaciones = $usuario->can('exportaciones.ver');
    $puedeGestionarExportaciones = $usuario->can('exportaciones.gestionar');
    $veCompras = $usuario->can('documentos-recibidos.ver');
    $veReportes = $usuario->can('reportes.ver');
    $veContabilidad = $veCompras || $veReportes; // grupo "Contabilidad" del sidebar
    $vePreparacion = $usuario->can('preparacion.ver'); // checklist "Preparar emisión real"
    $veClientes = $usuario->can('viewAny', App\Models\Cliente::class);
    $veProductos = $usuario->can('viewAny', App\Models\Producto::class);
    $veFacturacion = $usuario->can('viewAny', App\Models\Dte::class);

    // Activos por item (rutas actuales, sin cambios de lógica).
    $enPreparar = request()->routeIs('facturacion.preparar-produccion');
    $enReporteContadora = request()->routeIs('facturacion.reporte-contadora*');
    // "Facturación" cubre el listado y las pantallas de creación (CCF, NC, factura,
    // exportación), que ya no tienen enlace propio en el sidebar. La invalidación ya no
    // tiene enlace lateral: sus acciones viven dentro de la ficha de cada documento.
    $enCcfFacturas = request()->routeIs('facturacion.*') && ! $enPreparar && ! $enReporteContadora;

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

            {{-- Selector de áreas de trabajo. Solo se dibuja si el usuario ve más de
                 un área; con una sola (los cuatro roles históricos, y también el rol
                 de producción) no aparece nada nuevo en la barra. Nunca autoriza. --}}
            <x-area-selector :areas="$areasVisibles" :activa="$areaActiva" />

            {{-- Badges de modo DTE: SOLO en pantallas de Facturación/DTE (no en el resto
                 del sistema). No cambia ningún candado ni validación; es solo dónde se
                 muestra el aviso. Las vistas de facturación además llevan su propio
                 banner detallado (<x-modo-dte-aviso>), que no se toca. Se OCULTA en la
                 pantalla contable de Ventas (Reporte contadora), donde no es relevante. --}}
            @if ($modoDte && request()->routeIs('facturacion.*') && ! $enReporteContadora)
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
                        {{-- Cierre COMPLETO (Laravel + Cloudflare Access): solo tiene sentido en el
                             dominio público protegido por Access; en hosts locales no se muestra. --}}
                        @if (config('cloudflare_access.enabled') && strcasecmp(request()->getHost(), (string) config('cloudflare_access.allowed_host')) === 0)
                            <form method="POST" action="{{ route('logout.completo') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout.completo')"
                                        onclick="event.preventDefault(); this.closest('form').submit();">
                                    Cerrar sesión (también Cloudflare)
                                </x-dropdown-link>
                            </form>
                        @endif
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
        {{-- Sidebar del ÁREA ACTIVA. El área se deriva de la URL
             (AreaSistema::activaDesdeRequest), nunca de la sesión, y esto es solo
             presentación: quien autoriza es el middleware de cada grupo de rutas. --}}
        @include('layouts.partials.sidebar-'.$areaActiva->value)
    </aside>
</div>
