<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Producto: {{ $producto->nombre }}</h2>
            <a href="{{ route('productos.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $producto->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                        {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                    <div class="flex items-center gap-3">
                        @can('update', $producto)
                            <a href="{{ route('productos.edit', $producto) }}" class="text-indigo-600 hover:underline text-sm">Editar</a>
                            <form method="POST" action="{{ route('productos.toggle-activo', $producto) }}">
                                @csrf @method('PATCH')
                                <button class="text-amber-600 hover:underline text-sm">{{ $producto->activo ? 'Inactivar' : 'Activar' }}</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-gray-500">Código</dt><dd class="font-mono">{{ $producto->codigo }}</dd></div>
                    <div><dt class="text-gray-500">Código de barra</dt><dd class="font-mono">{{ $producto->codigo_barra ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Nombre</dt><dd>{{ $producto->nombre }}</dd></div>
                    <div><dt class="text-gray-500">Tipo de producto</dt><dd>{{ $producto->tipo_producto?->label() }}</dd></div>
                    <div><dt class="text-gray-500">Unidad de medida</dt><dd>{{ $producto->unidadMedida?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Precio unitario</dt><dd class="font-mono">${{ number_format($producto->precio_unitario, 4) }}</dd></div>
                    <div><dt class="text-gray-500">Tipo de impuesto</dt><dd>{{ $producto->tipo_impuesto?->label() }}</dd></div>
                    <div><dt class="text-gray-500">Maneja inventario</dt><dd>{{ $producto->maneja_inventario ? 'Sí (preparado, sin descuento de stock)' : 'No' }}</dd></div>
                    <div><dt class="text-gray-500">Ref. inventario</dt><dd>{{ $producto->producto_inventario_ref ?? '—' }}</dd></div>
                    <div class="md:col-span-2"><dt class="text-gray-500">Descripción</dt><dd>{{ $producto->descripcion ?? '—' }}</dd></div>
                    <div class="md:col-span-2"><dt class="text-gray-500">Observaciones</dt><dd>{{ $producto->observaciones ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Precios por cliente/sucursal --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-1">Precios por cliente</h3>
                <p class="text-xs text-gray-400 mb-4">
                    Prioridad al facturar: sucursal → cliente → precio general (${{ number_format($producto->precio_unitario, 2) }}).
                    El precio aplicado se congela en el documento.
                </p>

                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Cliente</th>
                                <th class="px-3 py-2">Sala / sucursal</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($producto->preciosCliente as $precio)
                                <tr>
                                    <td class="px-3 py-2">{{ $precio->cliente?->nombre ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $precio->clienteSucursal?->nombre ?? 'Todas' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($precio->precio, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $precio->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $precio->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        @can('update', $producto)
                                            <form method="POST" action="{{ route('productos.precios.toggle-activo', [$producto, $precio]) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button class="text-amber-600 hover:underline">{{ $precio->activo ? 'Inactivar' : 'Activar' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('productos.precios.destroy', [$producto, $precio]) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este precio?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Sin precios especiales. Se usa el precio general.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('update', $producto)
                    @if ($errors->any())
                        <div class="mb-3 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                            <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('productos.precios.store', $producto) }}"
                          x-data="{
                              clientes: @js($clientes->map(fn ($c) => ['id' => (string) $c->id, 'nombre' => $c->nombre, 'sucursales' => $c->sucursales->map(fn ($s) => ['id' => (string) $s->id, 'nombre' => $s->nombre])->values()])->values()),
                              clienteId: '',
                              get sucursales() { return this.clientes.find(c => c.id === this.clienteId)?.sucursales ?? []; },
                          }"
                          class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end border-t pt-4">
                        @csrf
                        <input type="hidden" name="activo" value="1">
                        <div>
                            <x-input-label for="precio_cliente_id" value="Cliente *" />
                            <select id="precio_cliente_id" name="cliente_id" x-model="clienteId"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                <option value="">— Seleccione —</option>
                                @foreach ($clientes as $c)
                                    <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="precio_sucursal_id" value="Sala (opcional)" />
                            <select id="precio_sucursal_id" name="cliente_sucursal_id"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                <option value="">— Todas —</option>
                                <template x-for="s in sucursales" :key="s.id">
                                    <option :value="s.id" x-text="s.nombre"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="precio_valor" value="Precio *" />
                            <input id="precio_valor" type="number" name="precio" step="0.0001" min="0"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                        </div>
                        <div class="md:col-span-1">
                            <x-input-label for="precio_obs" value="Observaciones" />
                            <input id="precio_obs" type="text" name="observaciones"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        </div>
                        <div>
                            <button class="w-full px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Agregar precio</button>
                        </div>
                    </form>
                @endcan
            </div>

            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-3">Historial de auditoría</h3>
                @forelse ($actividades as $actividad)
                    <div class="flex items-start gap-3 py-2 border-b border-gray-100 last:border-0 text-sm">
                        <div class="text-gray-400 whitespace-nowrap">{{ $actividad->created_at->format('d/m/Y H:i') }}</div>
                        <div>
                            <span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span>
                            {{ $actividad->description }}
                            @if ($actividad->properties->has('attributes'))
                                <span class="text-gray-400">
                                    ({{ collect($actividad->properties->get('attributes'))->keys()->implode(', ') }})
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Sin actividad registrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
