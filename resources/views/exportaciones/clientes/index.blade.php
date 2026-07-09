<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clientes de exportación</h2>
            <div class="flex gap-2">
                <a href="{{ route('exportaciones.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Listas de empaque</a>
                <a href="{{ route('exportaciones.clientes.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo cliente</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif

            <form method="GET" class="mb-4 flex flex-wrap items-center gap-3">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Buscar por nombre…"
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
                                <th class="py-3 px-4">Cliente</th>
                                <th class="py-3 px-4">FDA reg. number</th>
                                <th class="py-3 px-4">Contacto</th>
                                <th class="py-3 px-4 text-center">Productos con precio</th>
                                <th class="py-3 px-4 text-center">Activo</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($clientes as $cliente)
                                <tr class="hover:bg-gray-50 {{ $cliente->activo ? '' : 'opacity-60' }}">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('exportaciones.clientes.show', $cliente) }}" class="font-medium text-gray-800 hover:text-indigo-600">{{ $cliente->nombre }}</a>
                                        <div class="text-xs text-gray-500">{{ $cliente->direccion ?? '—' }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ $cliente->fda_reg_number ?? '—' }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ $cliente->contacto ?? '—' }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="inline-flex items-center justify-center min-w-[1.75rem] rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $cliente->productos_count }}</span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <form method="POST" action="{{ route('exportaciones.clientes.toggle-activo', $cliente) }}">
                                            @csrf @method('PATCH')
                                            <button class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                                {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('exportaciones.clientes.show', $cliente) }}" class="text-indigo-600 hover:underline">Precios</a>
                                            <a href="{{ route('exportaciones.clientes.edit', $cliente) }}" class="text-indigo-600 hover:underline">Editar</a>
                                            <form method="POST" action="{{ route('exportaciones.clientes.destroy', $cliente) }}"
                                                  onsubmit="return confirm('¿Eliminar este cliente y su lista de precios? Las exportaciones existentes conservan sus datos.');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline">Eliminar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-10 text-center text-gray-400">No hay clientes de exportación. <a href="{{ route('exportaciones.clientes.create') }}" class="text-indigo-600 hover:underline">Creá el primero</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($clientes->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $clientes->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
