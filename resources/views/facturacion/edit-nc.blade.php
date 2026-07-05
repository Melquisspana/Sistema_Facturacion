<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Nota de crédito #{{ $nc->id }} (borrador)
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
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

            {{-- Acción: generar --}}
            @can('update', $nc)
                <div class="bg-white shadow sm:rounded-lg p-4 flex items-center justify-between">
                    <p class="text-sm text-gray-600">Cuando termines de acreditar líneas, generá la nota de crédito (consume el correlativo y deja de ser editable).</p>
                    <form method="POST" action="{{ route('facturacion.generar', $nc) }}"
                          onsubmit="return confirm('¿Generar la nota de crédito? Ya no podrá editarse.');">
                        @csrf
                        <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm rounded-md hover:bg-green-700">Generar</button>
                    </form>
                </div>
            @endcan

            {{-- Cabecera --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Datos de la nota de crédito</h3>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Tipo</dt><dd>{{ $nc->tipo_nota_credito?->label() ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Documento original</dt>
                        <dd>
                            @if ($original)
                                <a href="{{ route('facturacion.show', $original) }}" class="text-indigo-600 hover:underline font-mono">{{ $original->numero_interno ?? ('CCF #'.$original->id) }}</a>
                            @else
                                <span class="text-amber-600">Sin documento relacionado</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">Cliente</dt><dd>{{ $nc->cliente?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Estado</dt><dd><x-estado-dte-badge :estado="$nc->estado" /></dd></div>
                    <div class="md:col-span-4"><dt class="text-gray-500">Motivo</dt><dd>{{ $nc->motivo ?? '—' }}</dd></div>
                </dl>
            </div>

            @if ($porProductos)
            {{-- Flujo 1: devolución/faltante → acreditar líneas del CCF original --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <p class="text-xs text-gray-500 mb-3">Use esta opción cuando la nota afecta productos o cantidades del CCF original.</p>
                <h3 class="font-semibold text-gray-700 mb-3">Líneas del documento original</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2 text-right">Cant. original</th>
                                <th class="px-3 py-2 text-right">Acreditada</th>
                                <th class="px-3 py-2 text-right">Disponible</th>
                                <th class="px-3 py-2">Acreditar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($lineasOriginales as $fila)
                                @php $lo = $fila['linea']; $disponible = $fila['disponible']; @endphp
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $lo->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($lo->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($lo->cantidad, '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($fila['acreditado'], '0'), '.') ?: '0' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($disponible, '0'), '.') ?: '0' }}</td>
                                    <td class="px-3 py-2">
                                        @if (\App\Support\Dinero::comparar($disponible, '0') > 0)
                                            <form method="POST" action="{{ route('facturacion.acreditar', [$nc, $lo]) }}" class="flex items-end gap-2">
                                                @csrf
                                                <input type="number" name="cantidad" step="0.0001" min="0.0001" max="{{ $disponible }}"
                                                       value="{{ rtrim(rtrim($disponible, '0'), '.') }}"
                                                       class="block w-24 border-gray-300 rounded-md shadow-sm text-sm" required>
                                                <button class="px-3 py-2 bg-indigo-600 text-white text-xs rounded-md hover:bg-indigo-700">Acreditar</button>
                                            </form>
                                        @else
                                            <span class="text-gray-400 text-xs">Sin saldo</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">El documento original no tiene líneas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @elseif ($porAveria)
            {{-- Flujo "avería": cualquier producto del catálogo (no limitado al CCF original) --}}
            <div class="bg-white shadow sm:rounded-lg p-6" x-data="{ filtro: '' }">
                <h3 class="font-semibold text-gray-700 mb-1">Productos para nota de crédito por avería</h3>
                <p class="text-xs text-gray-500 mb-3">La avería puede acreditar <strong>cualquier producto</strong> del catálogo, no solo los del CCF relacionado. El precio se aplica por cliente/sucursal.</p>

                <div class="mb-4">
                    <x-input-label for="filtro-averia" value="Filtrar por nombre, código interno o código de barra" />
                    <input id="filtro-averia" type="text" x-model="filtro"
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
                                                <form method="POST" action="{{ route('facturacion.averia.store', $nc) }}"
                                                      class="flex items-end gap-2">
                                                    @csrf
                                                    <input type="hidden" name="producto_id" value="{{ $p['id'] }}">
                                                    <div>
                                                        <label class="text-xs text-gray-500">Cantidad</label>
                                                        <input type="number" name="cantidad" value="1" step="1" min="1"
                                                               inputmode="numeric"
                                                               class="block w-20 border-gray-300 rounded-md shadow-sm text-sm" required>
                                                    </div>
                                                    <button class="px-3 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                                        Agregar a nota de crédito
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
            @else
            {{-- Flujo 2: pronto pago / descuento / ajuste → conceptos por monto --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-1">Agregar concepto de ajuste</h3>
                <p class="text-xs text-gray-500 mb-3">Estas líneas son <strong>conceptos de ajuste</strong>, no productos físicos. No afectan inventario.</p>
                <form method="POST" action="{{ route('facturacion.conceptos.store', $nc) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label for="descripcion" value="Concepto / descripción *" />
                        <x-text-input id="descripcion" name="descripcion" type="text" class="mt-1 block w-full"
                                      placeholder="Ej. Descuento por pronto pago" required />
                    </div>
                    <div>
                        <x-input-label for="monto" value="Monto *" />
                        <input id="monto" name="monto" type="number" step="0.01" min="0.01"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                    </div>
                    <div>
                        <x-input-label for="tipo_impuesto" value="Tratamiento" />
                        <select id="tipo_impuesto" name="tipo_impuesto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            @foreach ($tiposImpuesto as $valor => $label)
                                <option value="{{ $valor }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <button class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Agregar concepto</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Líneas/conceptos de esta NC --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">{{ $porProductos ? 'Líneas acreditadas' : ($porAveria ? 'Productos acreditados por avería' : 'Conceptos') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">#</th>
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                <th class="px-3 py-2 text-right">Gravado</th>
                                <th class="px-3 py-2 text-right">IVA</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($nc->lineas as $linea)
                                <tr>
                                    <td class="px-3 py-2">{{ $linea->numero_linea }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $linea->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->venta_gravada, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->iva_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->total_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" action="{{ route('facturacion.lineas.destroy', [$nc, $linea]) }}"
                                              onsubmit="return confirm('¿Quitar esta línea acreditada?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline text-xs">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">{{ $porProductos ? 'Aún no se acreditó ninguna línea.' : ($porAveria ? 'Aún no hay productos. Agrega uno desde el catálogo de arriba.' : 'Aún no hay conceptos. Agrega uno arriba.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Totales: partial único de presentación (no recalcula nada). --}}
            @include('facturacion.partials.totales', ['dte' => $nc])

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>
</x-app-layout>
