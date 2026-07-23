<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Facturación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif

                @php
                    // El badge de estado del DTE vive en <x-estado-dte-badge> (mismo componente
                    // que ficha/edición). Los colores del último envío de correo viven en el
                    // partial 'facturacion.partials.lista-dtes' (reutilizado por Auditoría).
                    $tiposOpciones = [
                        \App\Enums\TipoDte::CreditoFiscal->value => 'CCF',
                        \App\Enums\TipoDte::Factura->value => 'Factura consumidor final',
                        \App\Enums\TipoDte::FacturaExportacion->value => 'Factura de exportación',
                        \App\Enums\TipoDte::NotaCredito->value => 'Nota de crédito',
                    ];
                    $hoy = now()->toDateString();
                    $semIni = now()->startOfWeek()->toDateString();
                    $semFin = now()->endOfWeek()->toDateString();
                    $mesIni = now()->startOfMonth()->toDateString();
                    $mesFin = now()->endOfMonth()->toDateString();

                    // Contador de filtros activos: solo valores reales (sin contar "Todos"/vacíos).
                    $filtrosActivos = collect([
                        $filtros['q'] !== '',
                        filled($filtros['tipo_dte'] ?? null),
                        filled($filtros['estado'] ?? null),
                        filled($filtros['cliente_id'] ?? null),
                        filled($filtros['fecha_desde'] ?? null),
                        filled($filtros['fecha_hasta'] ?? null),
                    ])->filter()->count();
                    $panelAbiertoInicial = $filtrosActivos > 0
                        || $errors->hasAny(['q', 'tipo_dte', 'estado', 'cliente_id', 'fecha_desde', 'fecha_hasta']);

                    // Botones de creación: los 4 sólidos, misma altura/padding/radius/texto/foco,
                    // solo cambia el color de fondo por tipo (sin variante outline).
                    $btnBase = 'inline-flex items-center justify-center w-full lg:w-auto px-4 py-2.5 text-sm font-medium text-white '
                        .'rounded-lg shadow-sm transition-colors duration-150 '
                        .'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2';

                    // Accesos rápidos por estado/tipo: distinguir el activo según los filtros actuales.
                    $chipBase = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border transition-colors duration-150';
                    $chipInactivo = $chipBase.' bg-white border-gray-200 text-gray-600 hover:bg-gray-50';
                    $chipActivo = $chipBase.' bg-indigo-600 border-indigo-600 text-white';
                    $estadoActual = $filtros['estado'] ?? '';
                    $tipoActual = $filtros['tipo_dte'] ?? '';
                @endphp

                @can('create', App\Models\Dte::class)
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:flex-wrap gap-2 mb-4">
                        <a href="{{ route('facturacion.create-ccf') }}"
                           class="{{ $btnBase }} bg-indigo-600 hover:bg-indigo-700 focus-visible:ring-indigo-500">
                            Nuevo CCF
                        </a>
                        <a href="{{ route('facturacion.create-nota-credito') }}"
                           class="{{ $btnBase }} bg-rose-600 hover:bg-rose-700 focus-visible:ring-rose-500">
                            Nueva nota de crédito
                        </a>
                        <a href="{{ route('facturacion.create-factura') }}"
                           class="{{ $btnBase }} bg-emerald-600 hover:bg-emerald-700 focus-visible:ring-emerald-500">
                            Nueva factura consumidor final
                        </a>
                        <a href="{{ route('facturacion.create-exportacion') }}"
                           class="{{ $btnBase }} bg-sky-600 hover:bg-sky-700 focus-visible:ring-sky-500">
                            Nueva factura exportación
                        </a>
                    </div>
                @endcan

                {{-- El listado muestra el ambiente OPERATIVO ACTUAL de esta instalación (00 en
                     desarrollo/APITEST, 01 en el servidor de producción), con todos sus estados
                     (borrador incluido). Nunca mezcla ambos ambientes en la misma vista. El otro
                     ambiente vive escondido en Auditoría; a propósito no hay botón para verlo aquí. --}}
                <p class="mb-2 text-xs text-gray-400">Mostrando documentos del ambiente <strong class="text-gray-500">{{ $ambienteListado }}</strong> (el activo de esta instalación).</p>

                {{-- Accesos rápidos por tipo/estado (chips) --}}
                <div class="flex flex-wrap items-center gap-1.5 mb-2">
                    <span class="text-xs font-medium text-gray-400 mr-0.5">Accesos rápidos:</span>
                    <a href="{{ route('facturacion.index') }}" class="{{ $estadoActual === '' && $tipoActual === '' ? $chipActivo : $chipInactivo }}">Todos</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '03']) }}" class="{{ $tipoActual === '03' ? $chipActivo : $chipInactivo }}">CCF</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '01']) }}" class="{{ $tipoActual === '01' ? $chipActivo : $chipInactivo }}">Facturas consumidor final</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '05']) }}" class="{{ $tipoActual === '05' ? $chipActivo : $chipInactivo }}">Notas de crédito</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '11']) }}" class="{{ $tipoActual === '11' ? $chipActivo : $chipInactivo }}">Facturas de exportación</a>
                    <a href="{{ route('facturacion.index', ['estado' => 'pendientes']) }}" class="{{ $estadoActual === 'pendientes' ? $chipActivo : $chipInactivo }}">Pendientes</a>
                    <a href="{{ route('facturacion.index', ['estado' => 'aceptado']) }}" class="{{ $estadoActual === 'aceptado' ? $chipActivo : $chipInactivo }}">Aceptados</a>
                    <a href="{{ route('facturacion.index', ['estado' => 'rechazados_invalidados']) }}" class="{{ $estadoActual === 'rechazados_invalidados' ? $chipActivo : $chipInactivo }}">Rechazados / invalidados</a>
                </div>

                {{-- Buscador siempre visible + filtros avanzados colapsables --}}
                <form method="GET" action="{{ route('facturacion.index') }}"
                      x-data="{
                          open: @js($panelAbiertoInicial),
                          desde: '{{ $filtros['fecha_desde'] }}',
                          hasta: '{{ $filtros['fecha_hasta'] }}',
                      }"
                      class="mb-5">

                    {{-- Barra compacta: buscador principal + botón Filtros + Limpiar --}}
                    <div class="flex flex-col sm:flex-row gap-2">
                        <div class="flex-1">
                            <label for="q" class="sr-only">Buscar por número, orden de compra, cliente o sala</label>
                            <input id="q" name="q" type="text" value="{{ $filtros['q'] }}"
                                   placeholder="Buscar por número, orden de compra, cliente o sala..."
                                   class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button type="button" @click="open = !open"
                                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 whitespace-nowrap">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9M3.75 6h3.75m0 0a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zM3.75 12h9m8.25 0h-3.75m0 0a1.5 1.5 0 103 0 1.5 1.5 0 00-3 0zM10.5 18h9m-13.5 0h-3.75m0 0a1.5 1.5 0 103 0 1.5 1.5 0 00-3 0z" />
                                </svg>
                                Filtros
                                @if ($filtrosActivos > 0)
                                    <span class="inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-indigo-600 text-white text-[11px] font-semibold leading-none">{{ $filtrosActivos }}</span>
                                @endif
                            </button>
                            @if ($filtrosActivos > 0)
                                <a href="{{ route('facturacion.index') }}"
                                   class="inline-flex items-center px-3 py-2 text-sm text-gray-500 hover:text-gray-700 hover:underline whitespace-nowrap">
                                    Limpiar
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Panel de filtros avanzados: cerrado por defecto, abierto si hay filtros activos --}}
                    <div x-show="open" x-cloak x-transition.duration.150ms
                         class="mt-2 rounded-lg border border-gray-200 bg-gray-50/60 p-3 space-y-2">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div>
                                <x-input-label for="tipo_dte" value="Tipo de documento" />
                                <select id="tipo_dte" name="tipo_dte" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm py-1.5">
                                    <option value="">Todos</option>
                                    @foreach ($tiposOpciones as $valor => $label)
                                        <option value="{{ $valor }}" @selected(($filtros['tipo_dte'] ?? '') === $valor)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="estado" value="Estado" />
                                <select id="estado" name="estado" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm py-1.5">
                                    <option value="">Todos</option>
                                    @foreach (\App\Enums\EstadoDte::cases() as $estado)
                                        <option value="{{ $estado->value }}" @selected(($filtros['estado'] ?? '') === $estado->value)>{{ $estado->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="cliente_id" value="Cliente" />
                                <select id="cliente_id" name="cliente_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm py-1.5">
                                    <option value="">Todos</option>
                                    @foreach ($clientes as $c)
                                        <option value="{{ $c->id }}" @selected((string) ($filtros['cliente_id'] ?? '') === (string) $c->id)>{{ $c->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            <div>
                                <x-input-label for="fecha_desde" value="Fecha desde" />
                                <input id="fecha_desde" name="fecha_desde" type="date" x-model="desde"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm py-1.5">
                            </div>
                            <div>
                                <x-input-label for="fecha_hasta" value="Fecha hasta" />
                                <input id="fecha_hasta" name="fecha_hasta" type="date" x-model="hasta"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm py-1.5">
                            </div>
                            <div class="flex gap-1.5 pb-0.5">
                                <button type="button" @click="desde = '{{ $hoy }}'; hasta = '{{ $hoy }}'"
                                        class="px-2.5 py-1 rounded-full text-xs border bg-white border-gray-200 text-gray-600 hover:bg-gray-100">Hoy</button>
                                <button type="button" @click="desde = '{{ $semIni }}'; hasta = '{{ $semFin }}'"
                                        class="px-2.5 py-1 rounded-full text-xs border bg-white border-gray-200 text-gray-600 hover:bg-gray-100">Esta semana</button>
                                <button type="button" @click="desde = '{{ $mesIni }}'; hasta = '{{ $mesFin }}'"
                                        class="px-2.5 py-1 rounded-full text-xs border bg-white border-gray-200 text-gray-600 hover:bg-gray-100">Este mes</button>
                            </div>
                            <div class="flex gap-2 pb-0.5">
                                <x-primary-button>Filtrar</x-primary-button>
                                <a href="{{ route('facturacion.index') }}" class="px-3 py-2 text-sm text-gray-500 hover:underline">Limpiar</a>
                            </div>
                        </div>
                    </div>
                </form>

                @include('facturacion.partials.lista-dtes')
            </div>
        </div>
    </div>
</x-app-layout>
