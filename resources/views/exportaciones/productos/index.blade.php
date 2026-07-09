<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Productos de exportación</h2>
            <div class="flex gap-2">
                <a href="{{ route('exportaciones.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Listas de empaque</a>
                <a href="{{ route('exportaciones.productos.importar') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Importar desde plantilla</a>
                <a href="{{ route('exportaciones.productos.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo producto</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <form method="GET" class="mb-4 flex flex-wrap items-center gap-3">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Buscar por nombre o código…"
                       class="w-72 rounded-md border-gray-300 text-sm">
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="inactivos" value="1" @checked(request()->boolean('inactivos')) class="rounded border-gray-300">
                    Incluir inactivos
                </label>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Buscar</button>
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-4">Producto</th>
                                <th class="py-3 px-4">Unidad / empaque</th>
                                <th class="py-3 px-4 text-right">Unid./caja</th>
                                <th class="py-3 px-4 text-right">g/unid.</th>
                                <th class="py-3 px-4 text-right">Precio base</th>
                                <th class="py-3 px-4 text-right">Precio unidad</th>
                                <th class="py-3 px-4 text-right">Neto kg</th>
                                <th class="py-3 px-4 text-right">Bruto kg</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($productos as $producto)
                                <tr class="hover:bg-gray-50 {{ $producto->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4">
                                        <div class="font-medium text-gray-800">{{ $producto->nombre_es }}</div>
                                        <div class="text-xs text-gray-500">{{ $producto->nombre_en }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $producto->unidad ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ $producto->unidades_por_caja }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format((float) $producto->gramos_por_unidad, 2) }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-800">{{ $producto->precio_caja !== null ? '$'.number_format((float) $producto->precio_caja, 2) : '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600" title="Precio base ÷ unidades por caja (calculado)">{{ $producto->precioPorUnidad() !== null ? '$'.number_format($producto->precioPorUnidad(), 2) : '—' }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format((float) $producto->peso_neto_caja_kg, 2) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ number_format((float) $producto->peso_bruto_caja_kg, 2) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <form method="POST" action="{{ route('exportaciones.productos.toggle-activo', $producto) }}">
                                            @csrf @method('PATCH')
                                            <button class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $producto->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}"
                                                    title="Cambiar estado">
                                                {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('exportaciones.productos.edit', $producto) }}" class="text-indigo-600 hover:underline">Editar</a>
                                            <form method="POST" action="{{ route('exportaciones.productos.destroy', $producto) }}"
                                                  onsubmit="return confirm('¿Eliminar este producto del catálogo? Las exportaciones existentes conservan sus datos.');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline">Eliminar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="py-10 text-center text-gray-400">
                                        No hay productos de exportación.
                                        <a href="{{ route('exportaciones.productos.importar') }}" class="text-indigo-600 hover:underline">Importá el catálogo desde la plantilla</a>
                                        o <a href="{{ route('exportaciones.productos.create') }}" class="text-indigo-600 hover:underline">creá el primero</a>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($productos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $productos->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
