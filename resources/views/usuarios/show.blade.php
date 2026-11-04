{{-- Detalle de un usuario. Mismo aspecto que la ficha de Personal, y lleva a ella. --}}
@php
    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $dt = 'text-gray-500 dark:text-paper-300';
    $dd = 'text-gray-800 dark:text-paper-100';
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $rol = $usuario->roles->first()?->name;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $usuario->name }}</h2>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $usuario->activo ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-ink-700 dark:text-paper-300' }}">
                {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('status') }}</p>
            @endif
            @if (session('error'))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</p>
            @endif

            <div class="{{ $tarjeta }}">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Usuario</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex gap-2"><dt class="{{ $dt }}">Rol:</dt><dd class="{{ $dd }}">{{ $rol ? (\App\Enums\RolSistema::opciones()[$rol] ?? $rol) : '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="{{ $dt }}">Correo:</dt><dd class="{{ $dd }}">{{ $usuario->email }}</dd></div>
                    <div class="flex gap-2"><dt class="{{ $dt }}">Registrado:</dt><dd class="{{ $dd }}">{{ $usuario->created_at?->format('d/m/Y H:i') }}</dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('usuarios.edit', $usuario) }}" class="{{ $secundario }}">Editar</a>
                    <a href="{{ route('usuarios.password.edit', $usuario) }}" class="{{ $secundario }}">Cambiar contraseña</a>
                </div>
            </div>

            @if ($conPersonal)
                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Personal</h3>
                    @if ($ficha)
                        <p class="mt-3 text-sm {{ $dd }}">Es la cuenta de
                            @if ($verFicha)
                                <a href="{{ route('personal.empleados.show', 'p'.$ficha->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $ficha->nombre }}</a>.
                            @else
                                {{ $ficha->nombre }}.
                            @endif
                            Su nombre se corrige en su ficha de Personal.
                        </p>
                    @else
                        @if ($usuario->cuenta_externa)
                            <p class="mt-3 text-sm {{ $dd }}">Cuenta externa: no es empleada y no está en Personal. Si pasa a ser empleada, se enlaza con su persona.</p>
                        @else
                            <p class="mt-3 text-sm {{ $dd }}">Sin ficha en Personal.</p>
                        @endif
                        @if ($fichasSinUsuario === null)
                            <p class="mt-1 text-sm {{ $dt }}">Quien gestiona Personal puede enlazarla desde la ficha de la persona, en «Agregar acceso o módulos».</p>
                        @elseif ($fichasSinUsuario->isEmpty())
                            <p class="mt-1 text-sm {{ $dt }}">No hay personas sin usuario en Personal. Dala de alta en
                                <a href="{{ route('planilla.empleados.create') }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Personal</a> y enlazala desde su ficha.</p>
                        @else
                            <form method="POST" action="{{ route('usuarios.personal', $usuario) }}" class="mt-3 flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="min-w-56 flex-1">
                                    <label for="planilla_empleado_id" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Persona</label>
                                    <select id="planilla_empleado_id" name="planilla_empleado_id" required class="{{ $campo }}">
                                        <option value="">— Elegí —</option>
                                        @foreach ($fichasSinUsuario as $persona)
                                            <option value="{{ $persona->id }}" @selected((string) old('planilla_empleado_id') === (string) $persona->id)>{{ $persona->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="{{ $secundario }}">Enlazar</button>
                            </form>
                            <p class="mt-2 text-xs {{ $dt }}">El usuario toma el nombre de la ficha. Si es Vendedor, queda también en Rutas.</p>
                        @endif
                        @if ($errors->any())
                            <div role="alert" class="mt-3 rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            <div class="{{ $tarjeta }}">
                <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Historial</h3>
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-ink-700">
                    @forelse ($actividades as $actividad)
                        <li class="flex items-start gap-3 py-2">
                            <span class="whitespace-nowrap {{ $dt }}">{{ $actividad->created_at->format('d/m/Y H:i') }}</span>
                            <span class="{{ $dd }}"><span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span> {{ $actividad->description }}</span>
                        </li>
                    @empty
                        <li class="py-2 {{ $dt }}">Sin actividad registrada.</li>
                    @endforelse
                </ul>
            </div>

            <a href="{{ route('usuarios.index') }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-300">← Volver a usuarios</a>
        </div>
    </div>
</x-app-layout>
