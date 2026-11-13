@php
    use App\Http\Controllers\Facturacion\DteController;

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
    // Configuración, Auditoría y Salud se deciden por PERMISO, el mismo que exige
    // cada ruta. Antes el menú preguntaba por el rol administrador y la ruta por el
    // permiso: un rol con el permiso no veía la puerta.
    $veConfiguracion = $usuario->can('configuracion.gestionar');
    $veAuditoria = $usuario->can('auditoria.ver');
    $veSalud = $usuario->can('sistema.salud');
    $vePpq = $usuario->can('ppq.ver');
    $veExportaciones = $usuario->can('exportaciones.ver');
    $veCompras = $usuario->can('documentos-recibidos.ver');
    $veReportes = $usuario->can('reportes.ver');
    $veContabilidad = $veCompras || $veReportes; // grupo "Contabilidad" del sidebar
    $veClientes = $usuario->can('viewAny', App\Models\Cliente::class);
    $veProductos = $usuario->can('viewAny', App\Models\Producto::class);
    $veFacturacion = $usuario->can('viewAny', App\Models\Dte::class);
    // Gastos: doble condición porque el módulo tiene interruptor propio mientras se
    // construye (GASTOS_ENABLED). Ocultar NO autoriza: el candado real es
    // `modulo.gastos` + `permission:gastos.ver` en routes/gastos.php.
    $veGastos = App\Enums\ModuloSistema::Gastos->activo() && $usuario->can('gastos.ver');

    // ¿La fase 2 —recurrencias y avisos— está MIGRADA en esta base? Es otra pregunta
    // que «está encendido el módulo»: entre desplegar el código y correr las
    // migraciones hay una ventana, y durante esa ventana el menú no puede ofrecer
    // pantallas que van a fallar, ni —peor— consultar tablas que no existen.
    //
    // Se pregunta UNA vez acá y se hereda por @include. Antes el contador de avisos
    // consultaba la base desde el sidebar, y en una base sin migrar eso devolvía 500
    // en TODAS las pantallas, incluido el dashboard. Un menú no puede tumbar la
    // aplicación: ver App\Services\Gastos\InstalacionGastos.
    $gastosInstalacion = app(App\Services\Gastos\InstalacionGastos::class);
    $gastosFase2 = $veGastos && $gastosInstalacion->fase2Instalada();
    $gastosAvisosSinLeer = $gastosFase2 ? $gastosInstalacion->avisosSinLeer($usuario) : 0;

    // Planilla vive DENTRO del área «Personal», pero con su propia llave. Tener
    // `asistencia.ver` no muestra sus enlaces: los sueldos son confidenciales y hasta la
    // existencia del menú dice algo.
    //
    // Nótese que NO se pregunta nada a la base. La comprobación de si las tablas de
    // Planilla están migradas la hace el middleware `modulo.planilla` cuando alguien
    // entra; el menú no puede permitirse esa consulta, por la misma razón por la que el
    // contador de avisos dejó de hacerla.
    $vePlanilla = App\Enums\ModuloSistema::Planilla->activo() && $usuario->can('planilla.ver');
    // Las fichas de Personal (Empleados) no dependen de Planilla: la misma llave, sin el
    // interruptor (08/11/2026). Tampoco consultan la base.
    $vePersonal = $usuario->can('planilla.ver');

    // Activos por item (rutas actuales, sin cambios de lógica).
    // "Documentos fiscales" cubre el listado y TODAS las pantallas del documento —crear
    // CCF/NC/factura/exportación, la ficha, la edición, el PDF, el JSON, la
    // invalidación—, que no tienen enlace propio en el sidebar: crear e invalidar son
    // acciones del listado y de la ficha, no categorías.
    //
    // Se resuelve por el CONTROLADOR de la ruta actual y NO por «facturacion.* menos una
    // lista de excepciones». El prefijo `facturacion.` lo comparten pantallas que no son
    // documentos (el reporte contable, el checklist de preparación), así que con la regla
    // por descarte cada ruta nueva bajo ese prefijo encendía este item hasta que alguien
    // se acordara de excluirla. La pregunta positiva —«¿esta pantalla la sirve
    // DteController?»— no tiene lista que mantener: las 51 rutas de documentos viven en
    // ese controlador y solo en él.
    //
    // getControllerClass() devuelve el NOMBRE de la clase leyéndolo del array de acción;
    // no instancia el controlador, así que no dispara constructores ni consultas.
    $enDocumentosFiscales = request()->route()?->getControllerClass() === DteController::class;

    // Listas de empaque: viven en Ventas y facturación (facturacion.listas.*). Se
    // mantiene encendida también en las URL antiguas de /exportaciones, que ahora
    // redirigen ahí, para que el resaltado no parpadee durante el salto.
    $enListasEmpaque = request()->routeIs('facturacion.listas.*');

    // Grupos COLAPSABLES: cuál contiene la página actual. Se calcula acá —y no dentro
    // de cada grupo— para que el grupo de la ruta activa nazca abierto pase lo que pase
    // en localStorage. Es presentación pura: no autoriza nada.
    // «Productos» cubre nacionales Y la pestaña de exportación: productos.exportacion.*
    // cuelga del mismo prefijo de nombre, así que `productos.*` las enciende las dos y
    // no hay que mantener ninguna lista de excepciones.
    $grupoVentasActivo = $enDocumentosFiscales || $enListasEmpaque || request()->routeIs('clientes.*', 'productos.*', 'facturacion.ordenes-recibidas.*');
    $grupoCobrosActivo = request()->routeIs('ppq.*', 'cobros.*');
    $grupoContabilidadActivo = request()->routeIs('documentos-recibidos.*', 'contabilidad.*');
    $grupoGastosActivo = request()->routeIs('gastos.*');

    // APARTADO CONFIGURACIÓN: no es un área. Se entra desde la barra superior en
    // cualquier área y trae su propio índice (configuracion/_nav), así que en sus
    // pantallas no se dibuja la barra lateral de área: con ella, Auditoría o Salud
    // mostraban el menú de Facturación aunque nadie hubiera ido a Facturación.
    $enConfiguracion = App\Enums\AreaSistema::enApartadoConfiguracion();
@endphp

{{-- Navegación: topbar fija (logo + usuario; los badges de modo DTE aparecen SOLO
     en pantallas de Facturación) y sidebar izquierda agrupada por secciones
     (off-canvas en móvil). Solo UX/layout: mismas rutas, mismos roles/permisos,
     sin lógica de negocio nueva. --}}
<div x-data="{ sidebarAbierta: false }"
     @keydown.escape.window="if (sidebarAbierta) { sidebarAbierta = false; $refs.hamburguesa?.focus() }">

    {{-- ===== Topbar ===== --}}
    <nav class="fixed inset-x-0 top-0 z-40 h-16 border-b border-gray-200 bg-white dark:border-ink-600 dark:bg-ink-900">
        <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
            {{-- Hamburguesa (solo móvil). Lleva nombre accesible fijo —el icono cambia
                 de dibujo, no de significado— y declara qué panel abre y en qué estado
                 está, para que un lector de pantalla anuncie el cambio sin verlo. --}}
            @unless ($enConfiguracion)
            <button type="button" x-ref="hamburguesa"
                    @click="sidebarAbierta = ! sidebarAbierta"
                    aria-label="Menú de navegación"
                    aria-controls="sidebar-principal"
                    :aria-expanded="sidebarAbierta ? 'true' : 'false'"
                    class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100 dark:focus-visible:outline-indigo-400 lg:hidden">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path x-show="! sidebarAbierta" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path x-show="sidebarAbierta" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            @endunless

            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2">
                <x-application-logo class="block h-9 w-auto" />
                <span class="hidden text-sm font-semibold text-gray-800 dark:text-paper-100 sm:block">{{ \App\Support\NombreEmpresa::actual() }}</span>
            </a>

            {{-- Selector de áreas de trabajo. Solo se dibuja si el usuario ve más de
                 un área; con una sola (los cuatro roles históricos, y también el rol
                 de producción) no aparece nada nuevo en la barra. Nunca autoriza. --}}
            {{-- En Configuración ninguna área está «activa»: el selector sirve para
                 volver a una, sin fingir que se está en Facturación. --}}
            <x-area-selector :areas="$areasVisibles" :activa="$enConfiguracion ? null : $areaActiva" />

            {{-- Badges de modo DTE: SOLO en pantallas de Facturación/DTE (no en el resto
                 del sistema). No cambia ningún candado ni validación; es solo dónde se
                 muestra el aviso. Las vistas de facturación además llevan su propio
                 banner detallado (<x-modo-dte-aviso>), que no se toca. --}}
            @if ($modoDte && request()->routeIs('facturacion.*'))
                {{-- Celular (debajo de sm): las dos o tres insignias no caben en la barra de 64 px
                     y se encimaban con el engranaje. Queda UNA corta, del mismo color; el detalle
                     sigue en el banner de cada pantalla y en el title. --}}
                <span class="sm:hidden inline-flex shrink-0 items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold {{ ! empty($modoDte['modo_seguro']) ? 'bg-green-600 text-white' : ($modoDteBadge[$modoDte['color']] ?? 'bg-gray-100 text-gray-600') }}"
                      title="{{ $modoDte['detalle'] }}">
                    {{ ! empty($modoDte['modo_seguro']) ? 'NO EMITE' : 'MODO '.$modoDte['etiqueta'] }}
                </span>
                <div class="hidden sm:flex min-w-0 flex-wrap items-center gap-1.5 text-xs" title="{{ $modoDte['detalle'] }}">
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

            {{-- Configuración + Tema + Usuario / logout --}}
            <div class="ms-auto flex items-center gap-1">
                {{-- CONFIGURACIÓN, un apartado propio y no un grupo de Facturación: se
                     llega desde cualquier área. Visible por el mismo permiso que exige
                     la ruta. En el celular queda solo el engranaje, con su nombre
                     accesible. El contador de trabajos fallidos viaja con ella porque
                     «Salud del sistema» vive ahora dentro de Configuración. --}}
                @if ($veConfiguracion)
                    <a href="{{ route('configuracion.resumen') }}"
                       aria-label="Configuración"
                       @if ($enConfiguracion) aria-current="page" @endif
                       class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-2.5 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-400 {{ $enConfiguracion
                           ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300'
                           : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100' }}">
                        <x-sidebar-icon name="configuracion" class="h-5 w-5" />
                        <span class="hidden sm:inline">Configuración</span>
                        @if ($veSalud && ($jobsFallidos ?? 0) > 0)
                            <span class="inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700"
                                  title="{{ $jobsFallidos }} trabajos en cola fallidos (correos/DTE). Revisá Salud del sistema.">
                                {{ $jobsFallidos }}
                            </span>
                        @endif
                    </a>
                @endif
                <x-theme-toggle />
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:bg-transparent dark:text-paper-300 dark:hover:text-paper-100">
                            {{-- En el celular el nombre (dos o tres renglones) sacaba el menú de la
                                 pantalla: ahí va el ícono de persona, con el nombre para el lector. --}}
                            <span class="hidden sm:inline">{{ $usuario->name }}</span>
                            <svg class="h-5 w-5 sm:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <span class="sr-only sm:hidden">{{ $usuario->name }}</span>
                            <div class="ms-1">
                                <svg class="h-4 w-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                        {{-- Quien revisa la auditoría sin administrar la configuración
                             (contabilidad) la encuentra acá. Quien sí la administra la
                             tiene dentro de Configuración › Administración. --}}
                        @if ($veAuditoria && ! $veConfiguracion)
                            <x-dropdown-link :href="route('auditoria.index')">Auditoría</x-dropdown-link>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').requestSubmit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                        {{-- Cierre COMPLETO (Laravel + Cloudflare Access): solo tiene sentido en el
                             dominio público protegido por Access; en hosts locales no se muestra. --}}
                        @if (config('cloudflare_access.enabled') && strcasecmp(request()->getHost(), (string) config('cloudflare_access.allowed_host')) === 0)
                            <form method="POST" action="{{ route('logout.completo') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout.completo')"
                                        onclick="event.preventDefault(); this.closest('form').requestSubmit();">
                                    Cerrar sesión (también Cloudflare)
                                </x-dropdown-link>
                            </form>
                        @endif
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </nav>

    @unless ($enConfiguracion)
    {{-- Fondo oscuro al abrir la sidebar en móvil --}}
    <div x-show="sidebarAbierta" x-cloak @click="sidebarAbierta = false"
         class="fixed inset-0 z-20 bg-gray-900/50 lg:hidden"></div>

    {{-- ===== Sidebar ===== --}}
    <aside id="sidebar-principal" aria-label="Navegación principal"
           class="fixed bottom-0 left-0 top-16 z-30 w-64 -translate-x-full transform overflow-x-hidden overflow-y-auto border-r border-gray-200 bg-white transition-transform duration-150 dark:border-ink-600 dark:bg-ink-900 lg:translate-x-0"
           :class="sidebarAbierta ? 'translate-x-0' : '-translate-x-full'">
        {{-- Cambio de área DENTRO del panel. Existe porque el selector de la barra
             superior solo se dibuja desde lg, que es justo donde este panel deja de
             ser la navegación: sin esto, por debajo de lg no había ninguna forma de
             llegar a Producción, Cobros o Asistencia salvo escribir la URL.

             Dibuja EXACTAMENTE las mismas áreas que el selector de escritorio
             ($areasVisibles = módulo encendido Y permiso de entrada) y, como aquél,
             no aparece con una sola área. Nunca autoriza: entrar a un área la decide
             su middleware. --}}
        <x-area-selector-panel :areas="$areasVisibles" :activa="$areaActiva" />

        {{-- Sidebar del ÁREA ACTIVA. El área se deriva de la URL
             (AreaSistema::activaDesdeRequest), nunca de la sesión, y esto es solo
             presentación: quien autoriza es el middleware de cada grupo de rutas. --}}
        @include('layouts.partials.sidebar-'.$areaActiva->value)
    </aside>
    @endunless
</div>
