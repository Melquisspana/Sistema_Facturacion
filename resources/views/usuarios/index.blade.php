{{-- Lista de usuarios. Mismo aspecto que Personal › Empleados: cada cuenta es de una
     persona, y la columna «Personal» lleva a su ficha. --}}
@php
    $rotulos = \App\Enums\RolSistema::opciones();
    $columnas = $conPersonal ? 6 : 5;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Usuarios</h2>
            <a href="{{ route('usuarios.create') }}"
               class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Nuevo usuario</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('status') }}</p>
            @endif
            @if (session('error'))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</p>
            @endif

            @if ($conPersonal && $sinFicha > 0)
                <p role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ $sinFicha === 1 ? 'Hay 1 usuario sin ficha en Personal.' : 'Hay '.$sinFicha.' usuarios sin ficha en Personal.' }}
                    Siguen entrando; abrí cada uno para enlazarlo con su persona.
                    @if ($filtros['sin_ficha'])
                        <a href="{{ route('usuarios.index') }}" class="font-medium underline">Ver todos</a>
                    @else
                        <a href="{{ route('usuarios.index', ['sin_ficha' => 1]) }}" class="font-medium underline">Ver solo esos</a>
                    @endif
                </p>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-3">
                @if ($filtros['sin_ficha'])<input type="hidden" name="sin_ficha" value="1">@endif
                <div>
                    <label for="q" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Buscar</label>
                    <input id="q" type="text" name="q" value="{{ $filtros['q'] }}" placeholder="Nombre o correo…"
                           class="mt-1 w-72 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filtrar</button>
                @if ($filtros['q'] !== '')
                    <a href="{{ route('usuarios.index', $filtros['sin_ficha'] ? ['sin_ficha' => 1] : []) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Limpiar</a>
                @endif
            </form>

            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Usuarios</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:bg-ink-700 dark:text-paper-300">
                                <th scope="col" class="px-4 py-3">Nombre</th>
                                <th scope="col" class="px-4 py-3">Rol</th>
                                <th scope="col" class="px-4 py-3">Correo</th>
                                @if ($conPersonal)
                                    <th scope="col" class="px-4 py-3">Personal</th>
                                @endif
                                <th scope="col" class="px-4 py-3">Estado</th>
                                <th scope="col" class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                            @forelse ($usuarios as $usuario)
                                @php $rol = $usuario->roles->first()?->name; @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-ink-700 {{ $usuario->activo ? '' : 'opacity-60' }}">
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-paper-100">
                                        <a href="{{ route('usuarios.show', $usuario) }}" class="hover:underline">{{ $usuario->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $rol ? ($rotulos[$rol] ?? $rol) : '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $usuario->email }}</td>
                                    @if ($conPersonal)
                                        <td class="px-4 py-3">
                                            @if ($usuario->planillaEmpleado && $verFichas)
                                                <a href="{{ route('personal.empleados.show', 'p'.$usuario->planillaEmpleado->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $usuario->planillaEmpleado->nombre }}</a>
                                            @elseif ($usuario->planillaEmpleado)
                                                <span class="text-gray-700 dark:text-paper-100">{{ $usuario->planillaEmpleado->nombre }}</span>
                                            @else
                                                <a href="{{ route('usuarios.show', $usuario) }}" class="text-amber-700 hover:underline dark:text-amber-300">Sin ficha en Personal</a>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $usuario->activo ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300' }}">
                                            {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <a href="{{ route('usuarios.show', $usuario) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Ver</a>
                                        <a href="{{ route('usuarios.edit', $usuario) }}" class="ml-2 text-indigo-600 hover:underline dark:text-indigo-400">Editar</a>
                                        <a href="{{ route('usuarios.password.edit', $usuario) }}" class="ml-2 text-indigo-600 hover:underline dark:text-indigo-400">Contraseña</a>
                                        <form method="POST" action="{{ route('usuarios.toggle-activo', $usuario) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button class="ml-2 text-amber-700 hover:underline dark:text-amber-300">{{ $usuario->activo ? 'Inactivar' : 'Activar' }}</button>
                                        </form>
                                        @if ($usuario->id !== auth()->id())
                                            <form method="POST" action="{{ route('usuarios.destroy', $usuario) }}" class="inline"
                                                  onsubmit="return confirm('¿Eliminar este usuario?');">
                                                @csrf @method('DELETE')
                                                <button class="ml-2 text-red-600 hover:underline dark:text-red-400">Eliminar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $columnas }}" class="px-4 py-6 text-center text-gray-500 dark:text-paper-300">No hay usuarios.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>{{ $usuarios->links() }}</div>
        </div>
    </div>
</x-app-layout>
