{{-- Posibles repetidos: SOLO LECTURA. Ver App\Services\Planilla\PosiblesRepetidos. --}}
@php
    $modulos = ['planilla' => 'Personal', 'asistencia' => 'Asistencia', 'rutas' => 'Rutas', 'usuario' => 'Usuario'];
    $enlace = fn (array $p) => match ($p['tipo']) {
        'planilla' => route('personal.empleados.show', 'p'.$p['id']),
        'asistencia' => $veAsistencia ? route('personal.empleados.show', 'a'.$p['id']) : null,
        'rutas' => auth()->user()->can('rutas.personal.ver') ? route('rutas.personal.show', $p['id']) : null,
        'usuario' => auth()->user()->can('usuarios.gestionar') ? route('personal.accesos.show', $p['id']) : null,
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">Posibles repetidos</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-paper-300">
                Personas con el mismo primer nombre y algún apellido en común, cargadas en más de un lado sin enlazar.
                Es una sospecha: revisá cada grupo. Si es la misma persona, enlazala desde su ficha de Personal
                («Agregar acceso o módulos») o desde Usuarios. Esta pantalla no cambia nada.
            </p>

            @forelse ($grupos as $grupo)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                    <ul class="divide-y divide-gray-100 dark:divide-ink-700">
                        @foreach ($grupo as $p)
                            <li class="flex min-h-11 flex-wrap items-center gap-3 py-2 text-sm">
                                <span class="w-24 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-paper-300">{{ $modulos[$p['tipo']] }}</span>
                                @if ($url = $enlace($p))
                                    <a href="{{ $url }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $p['nombre'] }}</a>
                                @else
                                    <span class="text-gray-800 dark:text-paper-100">{{ $p['nombre'] }}</span>
                                @endif
                                @unless ($p['activo'])
                                    <span class="text-xs text-gray-500 dark:text-paper-300">(inactivo)</span>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">No se encontraron posibles repetidos.</p>
            @endforelse

            <a href="{{ route('personal.empleados') }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-300">← Volver a empleados</a>
        </div>
    </div>
</x-app-layout>
