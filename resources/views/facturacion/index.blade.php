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
                @endphp

                @can('create', App\Models\Dte::class)
                    <div class="flex flex-wrap justify-end gap-2 mb-4">
                        <a href="{{ route('facturacion.create-nota-credito') }}"
                           class="inline-flex items-center px-4 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">
                            Nueva nota de crédito
                        </a>
                        <a href="{{ route('facturacion.create-exportacion') }}"
                           class="inline-flex items-center px-4 py-2 bg-sky-600 text-white text-sm rounded-md hover:bg-sky-700">
                            Nueva exportación
                        </a>
                        <a href="{{ route('facturacion.create-factura') }}"
                           class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">
                            Nueva factura (consumidor final)
                        </a>
                        <a href="{{ route('facturacion.create-ccf') }}"
                           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            Nuevo CCF
                        </a>
                    </div>
                @endcan

                {{-- El listado muestra SOLO producción real (ambiente 01). Las pruebas/simulación
                     viven escondidas en Auditoría; a propósito no hay botón para verlas aquí. --}}
                <p class="mb-3 text-xs text-gray-400">Mostrando solo documentos de <strong class="text-gray-500">producción</strong> (ambiente 01), desde el CCF 1078.</p>

                {{-- Accesos rápidos (chips) --}}
                <div class="flex flex-wrap gap-2 mb-4">
                    @php
                        $chipBase = 'inline-flex items-center px-3 py-1 rounded-full text-xs border';
                        $chip = $chipBase.' bg-gray-50 border-gray-200 text-gray-600 hover:bg-gray-100';
                    @endphp
                    <a href="{{ route('facturacion.index') }}" class="{{ $chip }}">Todos</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '03', 'estado' => 'generado']) }}" class="{{ $chip }}">CCF generados</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '03', 'estado' => 'borrador']) }}" class="{{ $chip }}">CCF borradores</a>
                    <a href="{{ route('facturacion.index', ['tipo_dte' => '05']) }}" class="{{ $chip }}">Notas de crédito</a>
                    <a href="{{ route('facturacion.index', ['estado' => 'invalidado']) }}" class="{{ $chip }}">Anulados</a>
                    <a href="{{ route('facturacion.index', ['fecha_desde' => $hoy, 'fecha_hasta' => $hoy]) }}" class="{{ $chip }}">Hoy</a>
                    <a href="{{ route('facturacion.index', ['fecha_desde' => $semIni, 'fecha_hasta' => $semFin]) }}" class="{{ $chip }}">Esta semana</a>
                </div>

                {{-- Filtros --}}
                <form method="GET" action="{{ route('facturacion.index') }}" class="mb-5 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-4">
                        <x-input-label for="q" value="Buscar (número, orden de compra, cliente, sala, relacionado…)" />
                        <x-text-input id="q" name="q" type="text" class="mt-1 block w-full" :value="$filtros['q']"
                                      placeholder="Ej. INT-03-… u OC-123" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="tipo_dte" value="Tipo" />
                        <select id="tipo_dte" name="tipo_dte" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">Todos</option>
                            @foreach ($tiposOpciones as $valor => $label)
                                <option value="{{ $valor }}" @selected(($filtros['tipo_dte'] ?? '') === $valor)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="estado" value="Estado" />
                        <select id="estado" name="estado" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">Todos</option>
                            @foreach (\App\Enums\EstadoDte::cases() as $estado)
                                <option value="{{ $estado->value }}" @selected(($filtros['estado'] ?? '') === $estado->value)>{{ $estado->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="cliente_id" value="Cliente" />
                        <select id="cliente_id" name="cliente_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">Todos</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c->id }}" @selected((string) ($filtros['cliente_id'] ?? '') === (string) $c->id)>{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-1">
                        <x-input-label for="fecha_desde" value="Desde" />
                        <input id="fecha_desde" name="fecha_desde" type="date" value="{{ $filtros['fecha_desde'] }}"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    </div>
                    <div class="md:col-span-1">
                        <x-input-label for="fecha_hasta" value="Hasta" />
                        <input id="fecha_hasta" name="fecha_hasta" type="date" value="{{ $filtros['fecha_hasta'] }}"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                    </div>
                    <div class="md:col-span-12 flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('facturacion.index') }}" class="px-3 py-2 text-sm text-gray-500 hover:underline">Limpiar</a>
                    </div>
                </form>

                @include('facturacion.partials.lista-dtes')
            </div>
        </div>
    </div>
</x-app-layout>
