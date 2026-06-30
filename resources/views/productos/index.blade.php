<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Productos</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="GET" class="flex flex-wrap items-end gap-3 mb-6">
                    <div class="flex-1 min-w-48">
                        <x-input-label for="q" value="Buscar (código, código de barra, nombre, descripción)" />
                        <x-text-input id="q" name="q" type="text" class="mt-1 block w-full"
                            :value="$filtros['q']" placeholder="Escribe para buscar…" />
                    </div>
                    <div>
                        <x-input-label for="tipo_producto" value="Tipo" />
                        <select id="tipo_producto" name="tipo_producto" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            @foreach ($tiposProducto as $valor => $label)
                                <option value="{{ $valor }}" @selected($filtros['tipo_producto'] === $valor)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="activo" value="Estado" />
                        <select id="activo" name="activo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            <option value="1" @selected($filtros['activo'] === '1')>Activos</option>
                            <option value="0" @selected($filtros['activo'] === '0')>Inactivos</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button>Filtrar</x-primary-button>
                        <a href="{{ route('productos.index') }}" class="px-4 py-2 text-sm text-gray-500 hover:underline self-center">Limpiar</a>
                    </div>
                </form>

                @can('create', App\Models\Producto::class)
                    <div class="flex justify-end mb-4">
                        <a href="{{ route('productos.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            Nuevo producto
                        </a>
                    </div>
                @endcan

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Cód. barra</th>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Unidad</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2">Impuesto</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($productos as $producto)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $producto->codigo }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $producto->codigo_barra ?? '—' }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $producto->nombre }}</td>
                                    <td class="px-3 py-2">{{ $producto->tipo_producto?->label() }}</td>
                                    <td class="px-3 py-2">{{ $producto->unidadMedida?->nombre ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($producto->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2">{{ $producto->tipo_impuesto?->label() }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $producto->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('productos.show', $producto) }}" class="text-gray-600 hover:underline">Ver</a>
                                        @can('update', $producto)
                                            <a href="{{ route('productos.edit', $producto) }}" class="text-indigo-600 hover:underline ml-2">Editar</a>
                                            <form method="POST" action="{{ route('productos.toggle-activo', $producto) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button class="text-amber-600 hover:underline ml-2">{{ $producto->activo ? 'Inactivar' : 'Activar' }}</button>
                                            </form>
                                        @endcan
                                        @can('delete', $producto)
                                            <form method="POST" action="{{ route('productos.destroy', $producto) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este producto?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No se encontraron productos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $productos->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
