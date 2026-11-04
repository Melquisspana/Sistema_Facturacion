{{-- Página de una cuenta sin ficha en Personal: la cuenta externa, una de antes que falta
     enlazar o, sin Planilla instalada, cualquiera. Quien tiene ficha se ve en su ficha (el
     controlador redirige allá). Las acciones son las mismas de la ficha: _acciones. --}}
@php
    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $dt = 'text-gray-500 dark:text-paper-300';
    $dd = 'text-gray-800 dark:text-paper-100';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $rol = $usuario->roles->first()?->name;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $usuario->name }}</h1>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $usuario->activo ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300' }}">
                    {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                </span>
            </div>
            <a href="{{ route('personal.accesos.index') }}" class="{{ $secundario }}">Volver a accesos</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">{{ session('status') }}</p>
            @endif
            @if (session('error'))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</p>
            @endif

            <div class="{{ $tarjeta }}">
                <h2 class="text-base font-semibold text-gray-800 dark:text-paper-100">Acceso</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex gap-2"><dt class="{{ $dt }}">Rol:</dt><dd class="{{ $dd }}">{{ $rol ? (\App\Enums\RolSistema::opciones()[$rol] ?? $rol) : '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="{{ $dt }}">Correo:</dt><dd class="{{ $dd }}">{{ $usuario->email }}</dd></div>
                    <div class="flex gap-2"><dt class="{{ $dt }}">Registrado:</dt><dd class="{{ $dd }}">{{ $usuario->created_at?->format('d/m/Y H:i') }}</dd></div>
                </dl>

                @if ($conPersonal)
                    <p class="mt-3 text-sm {{ $dd }}">
                        @if ($ficha)
                            Es la cuenta de {{ $ficha->nombre }}. Su nombre se corrige en su ficha de Personal.
                        @elseif ($usuario->cuenta_externa)
                            Cuenta externa: no es empleada y no está en Personal. Si pasa a ser empleada, se enlaza desde su ficha, en «Agregar acceso o módulos».
                        @else
                            Sin ficha en Personal. Sigue entrando; enlazala desde la ficha de su persona, en «Agregar acceso o módulos»
                            @can('planilla.gestionar')
                                (o al <a href="{{ route('planilla.empleados.create', ['user_id' => $usuario->id]) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">darla de alta</a>, eligiendo esta cuenta como «Usuario existente»)
                            @endcan.
                        @endif
                    </p>
                @endif

                @include('personal.accesos._acciones', ['cuenta' => $usuario, 'ficha' => $ficha, 'actividades' => $actividades])

                @if (! $ficha && $usuario->id !== auth()->id())
                    <form method="POST" action="{{ route('personal.accesos.destroy', $usuario) }}" class="mt-4 border-t border-gray-100 pt-4 dark:border-ink-700"
                          onsubmit="return confirm('¿Eliminar esta cuenta? No se puede deshacer: si solo tiene que dejar de entrar, desactivala.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Eliminar cuenta</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
