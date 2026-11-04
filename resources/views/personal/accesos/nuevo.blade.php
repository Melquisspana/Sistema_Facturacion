{{-- Personal › Accesos › Nueva cuenta externa: la de la contadora, que no es empleada, solo
     con rol Contabilidad. A una persona del personal se le da acceso en su ficha. Sin Planilla
     instalada es el «Nuevo usuario» de siempre, con cualquier rol. Mismos campos y orden que
     la ficha: Nombre, Rol, Correo, Contraseña (_campos). --}}
@php
    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-100';
    $primario = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700';
    $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $conPersonal ? 'Nueva cuenta externa' : 'Nuevo usuario' }}</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    <p>Revisá estos datos. No se guardó nada.</p>
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="{{ $tarjeta }}">
                @if ($conPersonal)
                    <p class="mb-4 text-sm text-gray-600 dark:text-paper-300">
                        Para quien usa el sistema sin ser empleado: la contadora externa. No queda en Personal ni en la planilla.
                        A una persona del personal se le da acceso en su ficha de
                        <a href="{{ route('personal.empleados') }}" class="font-medium text-indigo-600 underline dark:text-indigo-400">Empleados</a>,
                        en «Agregar acceso o módulos».
                    </p>
                @endif

                <form method="POST" action="{{ route('personal.accesos.store') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    @if ($conPersonal)
                        <input type="hidden" name="cuenta_externa" value="1">
                    @endif

                    <div class="sm:col-span-2">
                        <label for="name" class="{{ $etiqueta }}">Nombre</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" class="{{ $campo }}">
                    </div>

                    @include('personal.accesos._campos', [
                        'obligatorio' => true,
                        'roles' => $conPersonal
                            ? [\App\Enums\RolSistema::Contabilidad->value => \App\Enums\RolSistema::Contabilidad->label()]
                            : \App\Enums\RolSistema::opciones(),
                    ])

                    @unless ($conPersonal)
                        <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700 sm:col-span-2 dark:text-paper-100">
                            <input type="hidden" name="activo" value="0">
                            <input type="checkbox" name="activo" value="1" @checked(old('activo', true))
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Activo
                        </label>
                    @endunless

                    <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:col-span-2 sm:flex-row sm:justify-end dark:border-ink-700">
                        <a href="{{ route('personal.accesos.index') }}" class="{{ $secundario }}">Cancelar</a>
                        <button type="submit" class="{{ $primario }}">{{ $conPersonal ? 'Crear cuenta externa' : 'Crear usuario' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
