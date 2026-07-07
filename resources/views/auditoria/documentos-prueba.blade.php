<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Auditoría · Documentos de prueba / simulación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('auditoria.index') }}" class="text-sm text-gray-500 hover:underline">← Volver a Auditoría</a>
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
                        Ambiente 00 · pruebas / piloto / simulación
                    </span>
                </div>

                <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800">
                    Estos documentos <strong>no son válidos ante Hacienda</strong> (pruebas, piloto o aceptaciones simuladas
                    en modo mock). No aparecen en el listado principal de facturación, que solo muestra producción real.
                </div>

                {{-- Filtro (busca dentro de las pruebas; el listado ya está acotado a ambiente 00). --}}
                <form method="GET" action="{{ route('auditoria.documentos_prueba') }}" class="mb-5 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-6">
                        <x-input-label for="q" value="Buscar (número, orden de compra)" />
                        <x-text-input id="q" name="q" type="text" class="mt-1 block w-full" :value="$filtros['q']"
                                      placeholder="Ej. DTE-03-… u OC-123" />
                    </div>
                    <div class="md:col-span-3">
                        <x-input-label for="tipo_dte" value="Tipo" />
                        <select id="tipo_dte" name="tipo_dte" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">Todos</option>
                            @foreach ([
                                \App\Enums\TipoDte::CreditoFiscal->value => 'CCF',
                                \App\Enums\TipoDte::Factura->value => 'Factura consumidor final',
                                \App\Enums\TipoDte::FacturaExportacion->value => 'Factura de exportación',
                                \App\Enums\TipoDte::NotaCredito->value => 'Nota de crédito',
                            ] as $valor => $label)
                                <option value="{{ $valor }}" @selected(($filtros['tipo_dte'] ?? '') === $valor)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <x-input-label for="estado" value="Estado" />
                        <select id="estado" name="estado" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">Todos</option>
                            @foreach (\App\Enums\EstadoDte::cases() as $estado)
                                <option value="{{ $estado->value }}" @selected(($filtros['estado'] ?? '') === $estado->value)>{{ $estado->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-12 flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('auditoria.documentos_prueba') }}" class="px-3 py-2 text-sm text-gray-500 hover:underline">Limpiar</a>
                    </div>
                </form>

                @include('facturacion.partials.lista-dtes')
            </div>
        </div>
    </div>
</x-app-layout>
