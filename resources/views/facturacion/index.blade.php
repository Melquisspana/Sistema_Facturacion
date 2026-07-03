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
                    // Clases literales para que el JIT de Tailwind las incluya (no interpolar).
                    $estadoClases = [
                        'borrador' => 'bg-gray-100 text-gray-700',
                        'generado' => 'bg-blue-100 text-blue-700',
                        'firmado' => 'bg-indigo-100 text-indigo-700',
                        'enviado' => 'bg-amber-100 text-amber-700',
                        'aceptado' => 'bg-green-100 text-green-700',
                        'rechazado' => 'bg-red-100 text-red-700',
                        'invalidado' => 'bg-rose-100 text-rose-700',
                    ];
                    // Estado del último envío de correo (mismos colores del card "Correo del cliente").
                    $correoClases = [
                        'enviado' => 'bg-green-100 text-green-700',
                        'simulado' => 'bg-violet-100 text-violet-700',
                        'pendiente' => 'bg-amber-100 text-amber-700',
                        'error' => 'bg-rose-100 text-rose-700',
                    ];
                    $correoEtiquetas = [
                        'enviado' => 'Enviado',
                        'simulado' => 'Simulado',
                        'pendiente' => 'Pendiente',
                        'error' => 'Fallido',
                    ];
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

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Número</th>
                                <th class="px-3 py-2">Relacionado</th>
                                <th class="px-3 py-2">Cliente / sala</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">Correo</th>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Orden compra</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dtes as $dte)
                                @php $esNc = $dte->tipo_dte === \App\Enums\TipoDte::NotaCredito; @endphp
                                <tr>
                                    <td class="px-3 py-2">{{ $dte->tipo_dte->label() }}</td>
                                    {{-- Número: control oficial si existe; si no, el interno/generado --}}
                                    <td class="px-3 py-2 font-mono text-xs">{{ $dte->numero_control ?? $dte->numero_interno ?? '—' }}</td>
                                    {{-- Relacionado: solo NC con CCF original (nunca a sí misma) --}}
                                    <td class="px-3 py-2 font-mono text-xs">
                                        @if ($esNc && $dte->dte_relacionado_id && (int) $dte->dte_relacionado_id !== (int) $dte->id)
                                            <a href="{{ route('facturacion.show', $dte->dteRelacionado) }}" class="text-indigo-600 hover:underline">
                                                {{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    {{-- Cliente fiscal + sala/sucursal --}}
                                    <td class="px-3 py-2">
                                        @if ($dte->cliente)
                                            <div class="font-medium text-gray-800">{{ $dte->cliente->nombre }}</div>
                                            @if ($dte->clienteSucursal)
                                                <div class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</div>
                                            @endif
                                        @else
                                            <span class="font-medium text-gray-800">Consumidor final</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $estadoClases[$dte->estado->value] ?? 'bg-gray-100 text-gray-700' }}">
                                            {{ $dte->estado->label() }}
                                        </span>
                                    </td>
                                    {{-- Último envío de correo (subquery ultimo_envio_estado; solo lectura) --}}
                                    <td class="px-3 py-2">
                                        @if ($dte->ultimo_envio_estado)
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $correoClases[$dte->ultimo_envio_estado] ?? 'bg-gray-100 text-gray-600' }}">
                                                {{ $correoEtiquetas[$dte->ultimo_envio_estado] ?? ucfirst($dte->ultimo_envio_estado) }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $dte->fecha_emision?->format('d/m/Y') }}</td>
                                    <td class="px-3 py-2">{{ $dte->numero_orden_compra ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($dte->total_pagar, 2) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('facturacion.show', $dte) }}" class="text-gray-600 hover:underline">Ver</a>
                                        @can('update', $dte)
                                            <a href="{{ route('facturacion.edit', $dte) }}" class="text-indigo-600 hover:underline ml-2">Editar</a>
                                        @endcan
                                        @can('delete', $dte)
                                            <form method="POST" action="{{ route('facturacion.destroy', $dte) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este borrador?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">No hay documentos con esos filtros.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $dtes->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
