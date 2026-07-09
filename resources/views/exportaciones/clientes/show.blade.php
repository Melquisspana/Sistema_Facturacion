<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $cliente->nombre }}
                <span class="ms-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium align-middle {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $cliente->activo ? 'Activo' : 'Inactivo' }}</span>
            </h2>
            <div class="flex gap-2">
                <a href="{{ route('exportaciones.clientes.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Volver</a>
                <a href="{{ route('exportaciones.clientes.edit', $cliente) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Editar cliente</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            {{-- Datos del cliente --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Dirección</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->direccion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">FDA reg. number</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->fda_reg_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">Contacto</dt>
                        <dd class="mt-0.5 text-gray-800">{{ $cliente->contacto ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Lista de precios del cliente --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Productos y precios del cliente</h3>
                    @if ($disponibles->isNotEmpty())
                        <form method="POST" action="{{ route('exportaciones.clientes.productos.asignar-catalogo', $cliente) }}"
                              onsubmit="return confirm('¿Asignar todos los productos activos del catálogo que faltan, con su precio base? Después podés ajustar los precios uno por uno.');">
                            @csrf
                            <button class="rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200"
                                    title="Agrega todo el catálogo activo que falte usando el precio base como punto de partida">
                                Asignar todo el catálogo (precio base)
                            </button>
                        </form>
                    @endif
                </div>

                {{-- Agregar producto --}}
                <form method="POST" action="{{ route('exportaciones.clientes.productos.store', $cliente) }}"
                      class="flex flex-wrap items-end gap-3 px-6 py-4 bg-gray-50 border-b border-gray-100">
                    @csrf
                    <div class="grow max-w-md">
                        <label class="block text-xs font-medium text-gray-500">Producto del catálogo</label>
                        <select name="exportacion_producto_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">— elegí un producto —</option>
                            @foreach ($disponibles as $producto)
                                <option value="{{ $producto->id }}" @selected((string) old('exportacion_producto_id') === (string) $producto->id)>
                                    {{ $producto->nombre_es }}{{ $producto->precio_caja !== null ? ' (base $'.number_format((float) $producto->precio_caja, 2).')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('exportacion_producto_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500">Precio por caja ($)</label>
                        <input type="number" name="precio_caja" value="{{ old('precio_caja') }}" required min="0" step="0.01"
                               class="mt-1 w-32 rounded-md border-gray-300 text-sm text-right">
                        @error('precio_caja') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Asignar</button>
                    @if ($disponibles->isEmpty())
                        <p class="text-xs text-gray-400">Todo el catálogo activo ya está asignado a este cliente.</p>
                    @endif
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Producto</th>
                                <th class="py-3 px-4">Empaque</th>
                                <th class="py-3 px-4 text-right">Unid./caja</th>
                                <th class="py-3 px-4 text-right">Precio base</th>
                                <th class="py-3 px-4 text-right">Precio cliente</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($cliente->productos->sortBy(fn ($a) => $a->producto?->nombre_es) as $asignacion)
                                <tr class="hover:bg-gray-50 {{ $asignacion->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800">{{ $asignacion->producto?->nombre_es ?? '(producto eliminado)' }}</div>
                                        <div class="text-xs text-gray-500">{{ $asignacion->producto?->nombre_en }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $asignacion->producto?->unidad ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $asignacion->producto?->unidades_por_caja }}</td>
                                    <td class="py-3 px-4 text-right text-gray-400">
                                        {{ $asignacion->producto?->precio_caja !== null ? '$'.number_format((float) $asignacion->producto->precio_caja, 2) : '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.update', [$cliente, $asignacion]) }}" class="flex items-center justify-end gap-2">
                                            @csrf @method('PATCH')
                                            <input type="number" name="precio_caja" value="{{ $asignacion->precio_caja }}" required min="0" step="0.01"
                                                   class="w-28 rounded-md border-gray-300 text-sm text-right font-semibold">
                                            <button class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-700 hover:bg-gray-200" title="Guardar precio">Guardar</button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.update', [$cliente, $asignacion]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="toggle_activo" value="1">
                                            <button class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $asignacion->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                                {{ $asignacion->activo ? 'Sí' : 'No' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" action="{{ route('exportaciones.clientes.productos.destroy', [$cliente, $asignacion]) }}"
                                              onsubmit="return confirm('¿Quitar este producto de la lista del cliente?');">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline text-xs">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-10 text-center text-gray-400">Este cliente no tiene productos asignados. Asignale productos con su precio, o usá "Asignar todo el catálogo".</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-gray-400">
                Regla: si a otro cliente solo le cambia el PRECIO, es el mismo producto con precio específico aquí.
                Si cambia empaque, presentación, unidades por caja, gramos o pesos, crealo como otra presentación en el
                <a href="{{ route('exportaciones.productos.index') }}" class="text-indigo-600 hover:underline">catálogo maestro</a>.
            </p>
        </div>
    </div>
</x-app-layout>
