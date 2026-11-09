{{-- Personal › Accesos: quién entra al sistema (todos; lo ve solo quien administra accesos).
     Antes era el módulo «Usuarios». El acceso de una persona del personal se da y se cambia
     en su ficha; acá solo se crea la cuenta externa y se llega a las cuentas sin ficha. --}}
@php
    $rotulos = \App\Enums\RolSistema::opciones();
    $columnas = $conPersonal ? 6 : 5;
    $primario = 'inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    // Quien tiene ficha se abre en su ficha; el resto, en su página de acceso.
    $destino = fn ($u) => $conPersonal && $verFichas && $u->planillaEmpleado
        ? route('personal.empleados.show', 'p'.$u->planillaEmpleado->id)
        : route('personal.accesos.show', $u);
    // Lo que se conserva al filtrar o limpiar la búsqueda.
    $vista = array_filter(['sin_ficha' => $filtros['sin_ficha'] ? 1 : null, 'externas' => $filtros['externas'] ? 1 : null]);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $filtros['externas'] ? 'Accesos externos' : 'Accesos al sistema' }}</h1>
            <div class="flex flex-wrap items-center gap-3">
                @if ($filtros['externas'])
                    <a href="{{ route('personal.accesos.index') }}" class="{{ $secundario }}">Todos los accesos</a>
                @else
                    <a href="{{ route('personal.accesos.index', ['externas' => 1]) }}" class="{{ $secundario }}">Accesos externos</a>
                @endif
                <a href="{{ route('personal.accesos.create') }}" class="{{ $primario }}">Nueva cuenta externa</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">{{ session('status') }}</p>
            @endif
            @if (session('error'))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</p>
            @endif

            @if ($filtros['externas'])
                <p class="text-sm text-gray-600 dark:text-paper-300">
                    Cuentas de gente de afuera, sin ficha en Personal: la contadora. Solo pueden tener el rol Contabilidad.
                </p>
            @elseif ($conPersonal)
                <p class="text-sm text-gray-600 dark:text-paper-300">
                    El acceso de cada persona se da y se cambia en su ficha de
                    <a href="{{ route('personal.empleados') }}" class="font-medium text-indigo-600 underline dark:text-indigo-400">Empleados</a>,
                    en «Agregar acceso o módulos» y «Acceso y módulos». Acá se crea solo la cuenta externa: la contadora, que no es empleada.
                </p>
            @endif

            @if ($conPersonal && $sinFicha > 0 && ! $filtros['externas'])
                <p role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    {{ $sinFicha === 1 ? 'Hay 1 usuario sin ficha en Personal.' : 'Hay '.$sinFicha.' usuarios sin ficha en Personal.' }}
                    Siguen entrando; enlazalos desde la ficha de su persona, en «Agregar acceso o módulos».
                    @if ($filtros['sin_ficha'])
                        <a href="{{ route('personal.accesos.index') }}" class="font-medium underline">Ver todos</a>
                    @else
                        <a href="{{ route('personal.accesos.index', ['sin_ficha' => 1]) }}" class="font-medium underline">Ver solo esos</a>
                    @endif
                </p>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-3">
                @foreach ($vista as $nombre => $valor)<input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">@endforeach
                <div class="w-full sm:w-72">
                    <label for="q" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Buscar</label>
                    <input id="q" type="text" name="q" value="{{ $filtros['q'] }}" placeholder="Nombre o correo…"
                           class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                </div>
                <button class="{{ $secundario }}">Filtrar</button>
                @if ($filtros['q'] !== '')
                    <a href="{{ route('personal.accesos.index', $vista) }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Limpiar</a>
                @endif
            </form>

            <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:shadow-none dark:ring-ink-600 sm:rounded-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Accesos al sistema</caption>
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
                                        <a href="{{ $destino($usuario) }}" class="hover:underline">{{ $usuario->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $rol ? ($rotulos[$rol] ?? $rol) : '—' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-paper-300">{{ $usuario->email }}</td>
                                    @if ($conPersonal)
                                        <td class="px-4 py-3">
                                            @if ($usuario->planillaEmpleado)
                                                <span class="text-gray-700 dark:text-paper-100">{{ $usuario->planillaEmpleado->nombre }}</span>
                                            @elseif ($usuario->cuenta_externa)
                                                <span class="text-gray-500 dark:text-paper-300">Cuenta externa</span>
                                            @else
                                                <span class="text-amber-700 dark:text-amber-300">Sin ficha en Personal</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $usuario->activo ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300' }}">
                                            {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <a href="{{ $destino($usuario) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ $conPersonal && $verFichas && $usuario->planillaEmpleado ? 'Abrir ficha' : 'Abrir' }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $columnas }}" class="px-4 py-10 text-center text-gray-500 dark:text-paper-300">
                                        @if ($filtros['externas'] && $filtros['q'] === '')
                                            Todavía no hay accesos externos. <a href="{{ route('personal.accesos.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Crear el primero</a>
                                        @elseif ($filtros['q'] !== '' || $filtros['sin_ficha'] || $filtros['externas'])
                                            Nada coincide con estos filtros. <a href="{{ route('personal.accesos.index') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Limpiar filtros</a>
                                        @else
                                            Todavía no hay accesos.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($usuarios->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3 dark:border-ink-700">{{ $usuarios->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
