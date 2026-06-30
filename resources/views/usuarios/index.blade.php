<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Usuarios</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                <div class="flex items-end justify-between gap-3 mb-6">
                    <form method="GET" class="flex-1 max-w-md">
                        <x-input-label for="q" value="Buscar (nombre o correo)" />
                        <div class="flex gap-2 mt-1">
                            <x-text-input id="q" name="q" type="text" class="block w-full" :value="$filtros['q']" />
                            <x-primary-button>Buscar</x-primary-button>
                        </div>
                    </form>
                    <a href="{{ route('usuarios.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        Nuevo usuario
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Correo</th>
                                <th class="px-3 py-2">Rol</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($usuarios as $usuario)
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $usuario->name }}</td>
                                    <td class="px-3 py-2">{{ $usuario->email }}</td>
                                    <td class="px-3 py-2">{{ $usuario->roles->first()?->name ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $usuario->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ route('usuarios.show', $usuario) }}" class="text-gray-600 hover:underline">Ver</a>
                                        <a href="{{ route('usuarios.edit', $usuario) }}" class="text-indigo-600 hover:underline ml-2">Editar</a>
                                        <a href="{{ route('usuarios.password.edit', $usuario) }}" class="text-blue-600 hover:underline ml-2">Contraseña</a>
                                        <form method="POST" action="{{ route('usuarios.toggle-activo', $usuario) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button class="text-amber-600 hover:underline ml-2">{{ $usuario->activo ? 'Inactivar' : 'Activar' }}</button>
                                        </form>
                                        @if ($usuario->id !== auth()->id())
                                            <form method="POST" action="{{ route('usuarios.destroy', $usuario) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este usuario?');">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No hay usuarios.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $usuarios->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
