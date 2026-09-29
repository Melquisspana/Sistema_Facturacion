@php
    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Personas en planilla</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">
            @if (session('planilla.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
                Estas personas ya existen en el sistema. Se <strong>reutilizan</strong>, no se vuelven a escribir:
                cada una queda enganchada a su ficha de Asistencia, de Personal de Rutas o de usuario.
                <strong>No hace falta huella</strong> ni que el módulo de Asistencia esté encendido.
            </p>

            @if ($empleados->isNotEmpty())
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Registro de personas en planilla</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">Nombre</th>
                                <th scope="col" class="px-4 py-2 font-medium">Cargo</th>
                                <th scope="col" class="px-4 py-2 font-medium">Identidad</th>
                                @if ($verImportes)
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Salario de referencia</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($empleados as $e)
                                <tr class="{{ $e->activo ? '' : 'opacity-60' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-900">
                                        {{ $e->nombre }}
                                        @unless ($e->activo)<span class="ml-1 text-xs text-gray-500">(inactiva)</span>@endunless
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-600">{{ $e->cargo ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-600">{{ $e->origenIdentidad() }}</td>
                                    @if ($verImportes)
                                        <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ $e->salario_referencia ?: '—' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @can('planilla.gestionar')
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Agregar a alguien</h2>

                    @if ($candidatos !== [])
                        <p class="mt-2 text-sm text-gray-600">Personas que ya están en el sistema:</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($candidatos as $cand)
                                <form method="POST" action="{{ route('planilla.empleados.store') }}"
                                      class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 px-3 py-2">
                                    @csrf
                                    <input type="hidden" name="origen_tipo" value="{{ $cand['tipo'] }}">
                                    <input type="hidden" name="origen_id" value="{{ $cand['id'] }}">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-medium text-gray-900">{{ $cand['nombre'] }}</span>
                                        <span class="block text-xs text-gray-500">{{ $cand['origen'] }} · {{ $cand['detalle'] }}</span>
                                    </span>
                                    <button type="submit" class="inline-flex min-h-9 flex-none items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50">Agregar</button>
                                </form>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-sm text-gray-600">Ya están todas las personas del sistema.</p>
                    @endif

                    {{-- El único caso en que se escribe a alguien nuevo: personal que no
                         está en ningún otro módulo. --}}
                    <details class="mt-4">
                        <summary class="min-h-11 cursor-pointer py-2 text-sm font-medium text-indigo-700">Alguien que no está en ningún módulo</summary>
                        <form method="POST" action="{{ route('planilla.empleados.store') }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <div>
                                <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre</label>
                                <input id="nombre" name="nombre" type="text" maxlength="180" required value="{{ old('nombre') }}" class="{{ $c }}">
                            </div>
                            <div>
                                <label for="cargo" class="block text-sm font-medium text-gray-700">Cargo</label>
                                <input id="cargo" name="cargo" type="text" maxlength="120" value="{{ old('cargo') }}" class="{{ $c }}">
                            </div>
                            <div>
                                <label for="codigo" class="block text-sm font-medium text-gray-700">Código (opcional)</label>
                                <input id="codigo" name="codigo" type="text" maxlength="40" value="{{ old('codigo') }}" class="{{ $c }}">
                            </div>
                            <div>
                                <label for="salario_referencia" class="block text-sm font-medium text-gray-700">Salario de referencia</label>
                                <input id="salario_referencia" name="salario_referencia" type="text" inputmode="decimal" value="{{ old('salario_referencia') }}" class="{{ $c }}">
                                <p class="mt-1 text-xs text-gray-500">Solo para prellenar. En cada planilla se puede cambiar.</p>
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Agregar</button>
                            </div>
                        </form>
                    </details>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
