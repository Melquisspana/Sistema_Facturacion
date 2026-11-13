<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Productos</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-4 sm:p-6">

                {{-- Selector de la entrada única «Productos». Es lo ÚNICO que se agregó a
                     esta pantalla: los filtros, las columnas, la paginación, la policy y
                     el controlador de productos nacionales quedaron exactamente igual. --}}
                <x-productos.selector activo="nacionales" />

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif

                @php
                    $filtrosPuestos = collect([$filtros['q'] ?? '', $filtros['tipo_producto'] ?? '', $filtros['activo'] ?? ''])->filter(fn ($v) => filled($v))->count();
                @endphp
                {{-- En el celular los filtros se pliegan detrás de «Filtros»; en escritorio, igual que siempre. --}}
                <x-filtros-celular :activos="$filtrosPuestos" id="filtros-productos">
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
                        <x-primary-button class="min-h-11 sm:min-h-0">Filtrar</x-primary-button>
                        <a href="{{ route('productos.index') }}" class="px-4 py-2 text-sm text-gray-500 hover:underline self-center">Limpiar</a>
                    </div>
                </form>
                </x-filtros-celular>

                @can('create', App\Models\Producto::class)
                    <div class="flex justify-end mb-4">
                        <a href="{{ route('productos.create') }}"
                           class="inline-flex min-h-11 sm:min-h-0 items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            Nuevo producto
                        </a>
                    </div>
                @endcan

                <div class="hidden md:block overflow-x-auto">
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

                {{-- Celular (debajo de md): una tarjeta por producto, con los mismos datos y acciones
                     como botones de 44 px. Desde md, la tabla de arriba. --}}
                <ul class="md:hidden divide-y divide-gray-100">
                    @forelse ($productos as $producto)
                        <li class="py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('productos.show', $producto) }}" class="break-words font-medium text-gray-900 hover:underline">{{ $producto->nombre }}</a>
                                    <p class="text-xs text-gray-500 font-mono">{{ $producto->codigo }}@if ($producto->codigo_barra) · {{ $producto->codigo_barra }}@endif</p>
                                </div>
                                <span class="shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs {{ $producto->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                                <span class="text-gray-600">{{ $producto->tipo_producto?->label() }} · {{ $producto->unidadMedida?->nombre ?? '—' }} · {{ $producto->tipo_impuesto?->label() }}</span>
                                <span class="shrink-0 font-mono font-semibold text-gray-900">${{ number_format($producto->precio_unitario, 2) }}</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <a href="{{ route('productos.show', $producto) }}"
                                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Ver</a>
                                @can('update', $producto)
                                    <a href="{{ route('productos.edit', $producto) }}"
                                       class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-indigo-700 hover:bg-gray-50">Editar</a>
                                    <form method="POST" action="{{ route('productos.toggle-activo', $producto) }}">
                                        @csrf @method('PATCH')
                                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-amber-700 hover:bg-gray-50">{{ $producto->activo ? 'Inactivar' : 'Activar' }}</button>
                                    </form>
                                @endcan
                                @can('delete', $producto)
                                    <form method="POST" action="{{ route('productos.destroy', $producto) }}"
                                          onsubmit="return confirm('¿Eliminar este producto?');">
                                        @csrf @method('DELETE')
                                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-red-700 hover:bg-gray-50">Eliminar</button>
                                    </form>
                                @endcan
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-center text-gray-400">No se encontraron productos.</li>
                    @endforelse
                </ul>

                <div class="mt-4">
                    {{ $productos->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
