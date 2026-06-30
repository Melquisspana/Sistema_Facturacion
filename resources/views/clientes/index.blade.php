<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Clientes</h2>
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
                        <x-input-label for="q" value="Buscar (nombre, documento, NRC, correo)" />
                        <x-text-input id="q" name="q" type="text" class="mt-1 block w-full"
                            :value="$filtros['q']" placeholder="Escribe para buscar…" />
                    </div>
                    <div>
                        <x-input-label for="tipo_cliente" value="Tipo" />
                        <select id="tipo_cliente" name="tipo_cliente" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            @foreach ($tiposCliente as $valor => $label)
                                <option value="{{ $valor }}" @selected($filtros['tipo_cliente'] === $valor)>{{ $label }}</option>
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
                        <a href="{{ route('clientes.index') }}" class="px-4 py-2 text-sm text-gray-500 hover:underline self-center">Limpiar</a>
                    </div>
                </form>

                @can('create', App\Models\Cliente::class)
                    <div class="flex justify-end mb-4">
                        <a href="{{ route('clientes.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            Nuevo cliente
                        </a>
                    </div>
                @endcan

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Documento</th>
                                <th class="px-3 py-2">NRC</th>
                                <th class="px-3 py-2">Ubicación</th>
                                <th class="px-3 py-2">Correo</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($clientes as $cliente)
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $cliente->nombre }}</td>
                                    <td class="px-3 py-2">{{ $cliente->tipo_cliente?->label() }}</td>
                                    <td class="px-3 py-2">
                                        {{ $cliente->tipo_documento?->label() }}
                                        <span class="font-mono">{{ $cliente->num_documento }}</span>
                                    </td>
                                    <td class="px-3 py-2 font-mono">{{ $cliente->nrc ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        @if ($cliente->tipo_cliente?->esExportacion())
                                            {{ $cliente->pais?->nombre ?? '—' }}
                                        @else
                                            {{ $cliente->municipio?->nombre ? $cliente->municipio->nombre.', '.$cliente->departamento?->nombre : '—' }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $cliente->correo ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $cliente->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('clientes.show', $cliente) }}" class="text-gray-600 hover:underline">Ver</a>
                                        @can('update', $cliente)
                                            <a href="{{ route('clientes.edit', $cliente) }}" class="text-indigo-600 hover:underline ml-2">Editar</a>
                                            <form method="POST" action="{{ route('clientes.toggle-activo', $cliente) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button class="text-amber-600 hover:underline ml-2">{{ $cliente->activo ? 'Inactivar' : 'Activar' }}</button>
                                            </form>
                                        @endcan
                                        @can('delete', $cliente)
                                            <form method="POST" action="{{ route('clientes.destroy', $cliente) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este cliente?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">No se encontraron clientes.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $clientes->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
