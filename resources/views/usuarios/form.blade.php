{{-- Nuevo y Editar usuario. Mismo aspecto, nombres y orden que la ficha de Personal:
     Persona (o Nombre), Rol, Correo, Contraseña. --}}
@php
    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-100';
    $primario = 'inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700';
    $desdePersonal = ! $usuario->exists && $conPersonal;
    // La persona se elige con permiso de Personal y si queda alguna sin usuario; la cuenta
    // externa (contadora que no es empleada) se puede crear siempre.
    $puedePersona = $desdePersonal && auth()->user()->can('planilla.gestionar') && $personasSinUsuario->isNotEmpty();
    $externa = $desdePersonal && (old('cuenta_externa') === '1' || ! $puedePersona);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">
            {{ $usuario->exists ? 'Editar usuario' : 'Nuevo usuario' }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            @if (session('error'))
                <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</p>
            @endif

            <div class="{{ $tarjeta }}">
                @if ($desdePersonal)
                    <p class="mb-4 text-sm text-gray-600 dark:text-paper-300">
                        Cada usuario es de una persona de Personal: elegila y ponele rol, correo y contraseña. El nombre sale de su ficha.
                        Si todavía no está, dala de alta en
                        <a href="{{ auth()->user()->can('planilla.gestionar') ? route('planilla.empleados.create') : route('personal.empleados') }}" class="font-medium text-indigo-600 underline dark:text-indigo-400">Personal</a>.
                        La única excepción es la contadora externa, que no es empleada.
                    </p>
                @endif

                    <form method="POST" action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}"
                          class="grid gap-3 sm:grid-cols-2" x-data="{ externa: {{ $externa ? 'true' : 'false' }} }">
                        @csrf
                        @if ($usuario->exists) @method('PUT') @endif

                        @if ($desdePersonal)
                            <fieldset class="sm:col-span-2">
                                <legend class="{{ $etiqueta }}">¿De quién es la cuenta?</legend>
                                <div class="mt-1 flex flex-wrap gap-x-6">
                                    <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700 dark:text-paper-100">
                                        <input type="radio" name="cuenta_externa" value="0" @checked(! $externa) @disabled(! $puedePersona) x-on:change="externa = false"
                                               class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        De una persona de Personal
                                    </label>
                                    <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700 dark:text-paper-100">
                                        <input type="radio" name="cuenta_externa" value="1" @checked($externa) x-on:change="externa = true"
                                               class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        Cuenta externa (contadora, no es empleada)
                                    </label>
                                </div>
                            </fieldset>

                            <div class="sm:col-span-2" x-show="! externa" @if ($externa) x-cloak @endif>
                                @if (! auth()->user()->can('planilla.gestionar'))
                                    <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">Para crear el usuario de una persona también hace falta poder gestionar Personal.</p>
                                @elseif ($personasSinUsuario->isEmpty())
                                    <p class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">Todas las personas de Personal ya tienen usuario.</p>
                                @else
                                    <label for="planilla_empleado_id" class="{{ $etiqueta }}">Persona</label>
                                    <select id="planilla_empleado_id" name="planilla_empleado_id" required x-bind:disabled="externa" class="{{ $campo }}">
                                        <option value="">— Elegí —</option>
                                        @foreach ($personasSinUsuario as $persona)
                                            <option value="{{ $persona->id }}" @selected((string) old('planilla_empleado_id', $personaElegida) === (string) $persona->id)>{{ $persona->nombre }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            <div class="sm:col-span-2" x-show="externa" @unless ($externa) x-cloak @endunless>
                                <label for="name" class="{{ $etiqueta }}">Nombre</label>
                                <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" x-bind:disabled="! externa"
                                       aria-describedby="name-externa" class="{{ $campo }}">
                                <p id="name-externa" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Solo con rol Contabilidad. No queda en Personal ni en la planilla.</p>
                            </div>
                        @else
                            <div class="sm:col-span-2">
                                <label for="name" class="{{ $etiqueta }}">Nombre</label>
                                @if ($ficha)
                                    {{-- Un solo nombre: el de quien está en Personal se corrige en su ficha. --}}
                                    <input id="name" name="name" type="text" value="{{ $ficha->nombre }}" readonly aria-describedby="name-ficha"
                                           class="{{ $campo }} bg-gray-50 dark:bg-ink-700">
                                    <p id="name-ficha" class="mt-1 text-xs text-gray-500 dark:text-paper-300">
                                        Se corrige en su <a href="{{ route('personal.empleados.show', 'p'.$ficha->id) }}" class="text-indigo-600 underline dark:text-indigo-400">ficha de Personal</a>.
                                    </p>
                                @else
                                    <input id="name" name="name" type="text" value="{{ old('name', $usuario->name) }}" required maxlength="255" class="{{ $campo }}">
                                @endif
                            </div>
                        @endif

                        @if ($usuario->exists)
                            <div>
                                <label for="rol" class="{{ $etiqueta }}">Rol</label>
                                <select id="rol" name="rol" required class="{{ $campo }}">
                                    <option value="">— Elegí —</option>
                                    @foreach ($roles as $valor => $texto)
                                        <option value="{{ $valor }}" @selected(old('rol', $rolActual) === $valor)>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="email" class="{{ $etiqueta }}">Correo</label>
                                <input id="email" name="email" type="email" value="{{ old('email', $usuario->email) }}" required maxlength="255" class="{{ $campo }}">
                            </div>
                        @else
                            @include('usuarios._campos-acceso', ['obligatorio' => true])
                        @endif

                        @unless ($desdePersonal)
                            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700 sm:col-span-2 dark:text-paper-100">
                                <input type="hidden" name="activo" value="0">
                                <input type="checkbox" name="activo" value="1" @checked(old('activo', $usuario->activo ?? true))
                                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                Activo
                            </label>
                        @endunless

                        <div class="flex items-center gap-3 sm:col-span-2">
                            <button type="submit" class="{{ $primario }}">{{ $usuario->exists ? 'Guardar' : 'Crear usuario' }}</button>
                            <a href="{{ $usuario->exists ? route('usuarios.show', $usuario) : route('usuarios.index') }}" class="text-sm text-gray-500 hover:underline dark:text-paper-300">Cancelar</a>
                        </div>
                    </form>
            </div>
        </div>
    </div>
</x-app-layout>
