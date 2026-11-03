{{-- «Nuevo» de Asistencia y de Rutas cuando las personas se cargan en Personal.

     Cada persona se da de alta una sola vez, en Personal. Desde acá no se vuelve a escribir
     a nadie: se elige a quien ya está y se abre su ficha, donde «Agregar acceso o módulos»
     le suma lo que falta con los mismos permisos de siempre. --}}
@php
    $gestiona = auth()->user()->can('planilla.gestionar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $titulo }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <p class="text-sm text-gray-600 dark:text-paper-300">
                    Cada persona se carga una sola vez, en Personal. Si ya está, abrí su ficha y en
                    «Agregar acceso o módulos» elegí «{{ $modulo }}». Si todavía no está, dala de alta en Personal.
                </p>

                @if ($gestiona)
                    <a href="{{ route('planilla.empleados.create') }}"
                       class="mt-4 inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">Dar de alta en Personal</a>
                @else
                    <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        Para dar de alta o agregarle algo a una persona hace falta poder gestionar Personal.
                    </p>
                @endif

                @if ($personas->isNotEmpty())
                    <h3 class="mt-6 text-sm font-semibold text-gray-800 dark:text-paper-100">En Personal y sin «{{ $modulo }}»</h3>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-ink-700">
                        @foreach ($personas as $persona)
                            <li class="flex min-h-11 items-center justify-between gap-3 py-2 text-sm">
                                <span class="text-gray-800 dark:text-paper-100">{{ $persona->nombre }}</span>
                                <a href="{{ route('personal.empleados.show', 'p'.$persona->id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">Abrir ficha</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <a href="{{ $volver }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-300">← Volver</a>
        </div>
    </div>
</x-app-layout>
