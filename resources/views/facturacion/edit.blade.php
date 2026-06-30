<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Editar borrador {{ $dte->tipo_dte->label() }} #{{ $dte->id }}
        </h2>
    </x-slot>

    @php $esFex = $dte->tipo_dte === \App\Enums\TipoDte::FacturaExportacion; @endphp
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Acción: generar (consume correlativo y pasa a "generado") --}}
            @can('update', $dte)
                <div class="bg-white shadow sm:rounded-lg p-4 flex items-center justify-between">
                    <p class="text-sm text-gray-600">
                        Cuando el borrador esté completo, generá el documento: consume el correlativo interno y deja de ser editable.
                    </p>
                    <form method="POST" action="{{ route('facturacion.generar', $dte) }}"
                          onsubmit="return confirm('¿Generar el documento? Ya no podrá editarse.');">
                        @csrf
                        <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700">
                            Generar
                        </button>
                    </form>
                </div>
            @endcan

            {{-- Cabecera (solo lectura en este paso) --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Datos del documento</h3>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Tipo</dt><dd>{{ $dte->tipo_dte->label() }}</dd></div>
                    <div><dt class="text-gray-500">Cliente</dt><dd>{{ $dte->cliente?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Sala / sucursal</dt><dd>{{ $dte->clienteSucursal?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Estado</dt><dd>{{ $dte->estado->label() }}</dd></div>
                    <div><dt class="text-gray-500">Fecha</dt><dd>{{ $dte->fecha_emision?->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-gray-500">Establecimiento</dt><dd>{{ $dte->establecimiento?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Punto de venta</dt><dd>{{ $dte->puntoVenta?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Orden de compra</dt><dd>{{ $dte->numero_orden_compra ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Retención IVA</dt><dd>{{ $dte->aplica_retencion_iva ? 'Sí' : 'No' }}</dd></div>
                </dl>
            </div>

            {{-- Agregar productos: catálogo disponible (ya visible) con filtro en vivo --}}
            @can('update', $dte)
            <div class="bg-white shadow sm:rounded-lg p-6" x-data="{ filtro: '' }">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-700">Productos disponibles</h3>
                    <span class="text-xs text-gray-400">{{ count($productosDisponibles) }} producto(s) activos</span>
                </div>

                {{-- B. Filtro rápido en vivo: el listado ya está visible, filtrar es opcional. --}}
                <div class="mb-4">
                    <x-input-label for="filtro-productos" value="Filtrar por nombre, código interno o código de barra" />
                    <input id="filtro-productos" type="text" x-model="filtro"
                           placeholder="Escribe para filtrar… (el listado ya está visible)"
                           class="mt-1 block w-full md:w-96 border-gray-300 rounded-md shadow-sm text-sm">
                </div>

                @if (count($productosDisponibles) === 0)
                    <p class="text-sm text-gray-400">No hay productos activos para agregar.</p>
                @else
                    <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-100 rounded-md">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr class="text-left text-gray-500">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Código barra</th>
                                    <th class="px-3 py-2">Producto</th>
                                    <th class="px-3 py-2 text-right">Precio aplicado</th>
                                    <th class="px-3 py-2">Cantidad</th>
                                    <th class="px-3 py-2">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($productosDisponibles as $p)
                                    <tr x-show="filtro === '' || @js($p['filtro']).includes(filtro.toLowerCase().trim())">
                                        <td class="px-3 py-2 font-mono">{{ $p['codigo'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-gray-500">{{ $p['codigo_barra'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @if ($p['sin_precio'])
                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Sin precio</span>
                                            @else
                                                <span class="font-mono">${{ $p['precio_fmt'] }}</span>
                                                <span class="block text-[10px] {{ $p['es_especial'] ? 'text-indigo-600' : 'text-gray-400' }}">{{ $p['origen_label'] }}</span>
                                            @endif
                                        </td>
                                        @if ($p['sin_precio'])
                                            <td colspan="2" class="px-3 py-2 text-xs text-gray-400">No se puede agregar sin precio.</td>
                                        @else
                                            <td colspan="2" class="px-3 py-2">
                                                <form method="POST" action="{{ route('facturacion.lineas.store', $dte) }}"
                                                      class="flex items-end gap-2">
                                                    @csrf
                                                    <input type="hidden" name="producto_id" value="{{ $p['id'] }}">
                                                    <div>
                                                        <label class="text-xs text-gray-500">Cantidad</label>
                                                        {{-- Cantidad entera: min 1, step 1 (sin decimales). --}}
                                                        <input type="number" name="cantidad" value="1" step="1" min="1"
                                                               inputmode="numeric"
                                                               class="block w-20 border-gray-300 rounded-md shadow-sm text-sm" required>
                                                    </div>
                                                    <button class="px-3 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                                        Agregar
                                                    </button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @endcan

            {{-- Líneas del documento --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Líneas</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">#</th>
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2">Cantidad</th>
                                <th class="px-3 py-2 text-right">{{ $esFex ? 'Exportación' : 'Gravado' }}</th>
                                <th class="px-3 py-2 text-right">IVA</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dte->lineas as $linea)
                                <tr>
                                    <td class="px-3 py-2">{{ $linea->numero_linea }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $linea->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <form method="POST" action="{{ route('facturacion.lineas.update', [$dte, $linea]) }}"
                                              class="flex items-end gap-2">
                                            @csrf @method('PATCH')
                                            <input type="number" name="cantidad" value="{{ (int) $linea->cantidad }}"
                                                   step="1" min="1"
                                                   class="block w-20 border-gray-300 rounded-md shadow-sm text-sm" required>
                                            <button class="text-indigo-600 hover:underline text-xs">Actualizar</button>
                                        </form>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($esFex ? $linea->venta_exportacion : $linea->venta_gravada, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->iva_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->total_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" action="{{ route('facturacion.lineas.destroy', [$dte, $linea]) }}"
                                              onsubmit="return confirm('¿Eliminar esta línea?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline text-xs">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Primero agregue productos al borrador.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Totales: partial único de presentación (no recalcula nada). --}}
            @include('facturacion.partials.totales', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null])

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>
</x-app-layout>
