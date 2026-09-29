@php
    use App\Services\Gastos\Dinero;
    use App\Models\Gastos\Regla;

    /* Una clase por estado de Regla::ESTADOS. El acceso es directo —$insignia[...]—,
       así que un estado sin entrada acá tumba la pantalla entera con «Undefined array
       key». Pasó al agregar «borrador»: el listado y el detalle dieron 500.

       Si mañana aparece otro estado, este mapa es lo primero que hay que tocar. El
       `?? ` del final evita que vuelva a ser un 500, pero no sustituye a completarlo. */
    $insignia = [
        'borrador' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'activa' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'pausada' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'cancelada' => 'bg-gray-100 text-gray-600 ring-gray-500/20',
    ];
    $claseEstado = fn (string $e) => $insignia[$e] ?? 'bg-gray-100 text-gray-600 ring-gray-500/20';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Gastos que se repiten</h1>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Ir a Por pagar</a>
                @can('gastos.recurrencias')
                    <a href="{{ route('gastos.reglas.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Nuevo</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-7xl space-y-4">
            <x-gastos-aviso />

            {{-- Esto no es dinero. Esta pantalla no muestra ni un total pendiente, y
                 lo dice, para que nadie la lea como un estado de cuenta. --}}
            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                Acá se configura <strong>qué se repite y cuándo</strong>. Lo que se debe hoy se mira en
                <a href="{{ route('gastos.panel') }}" class="font-medium text-indigo-600 underline">Gastos</a>:
                configurar algo acá no crea ninguna deuda por sí solo.
            </p>

            <nav class="flex flex-wrap gap-1 border-b border-gray-200" aria-label="Estado">
                @foreach (['' => 'Todas'] + Regla::ESTADOS as $clave => $texto)
                    <a href="{{ route('gastos.reglas.index', array_filter(['estado' => $clave])) }}"
                       @class([
                           'min-h-11 border-b-2 px-3 py-2 text-sm font-medium',
                           'border-indigo-500 text-indigo-600' => $estado === $clave,
                           'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $estado !== $clave,
                       ])
                       @if ($estado === $clave) aria-current="page" @endif>{{ $texto }}</a>
                @endforeach
            </nav>

            @if ($reglas->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                    <p class="text-sm text-gray-600">No hay nada configurado{{ $estado ? ' en ese estado' : ' todavía' }}.</p>
                    @can('gastos.recurrencias')
                        <a href="{{ route('gastos.reglas.create') }}" class="mt-3 inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Crear la primera</a>
                    @endcan
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {{-- En móvil, cada regla es un bloque: cinco columnas no entran en un
                         teléfono sin recortar la última. Desde `sm` vuelve la tabla. --}}
                    <ul class="divide-y divide-gray-100 sm:hidden">
                        @foreach ($reglas as $regla)
                            <li class="px-4 py-3">
                                <a href="{{ route('gastos.reglas.show', $regla) }}" class="block min-h-11">
                                    <p class="text-sm font-semibold text-gray-900">{{ $regla->nombre }}</p>
                                    <p class="text-sm text-gray-600">{{ $regla->beneficiario }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $calendario->enPalabras($regla->calendario()) }}</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full px-2 py-0.5 font-medium ring-1 ring-inset {{ $claseEstado($regla->estado) }}">{{ Regla::ESTADOS[$regla->estado] }}</span>
                                        <span class="text-gray-500">
                                            {{ $regla->monto_modo === 'fijo' ? $regla->moneda.' '.$regla->importe : 'Cambia cada vez' }}
                                        </span>
                                        <span class="text-gray-500">{{ $regla->generadas }} creado(s)</span>
                                    </p>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="hidden overflow-x-auto sm:block">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Gastos que se repiten</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Qué</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Cuándo</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Monto</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Estado</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Creados</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($reglas as $regla)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('gastos.reglas.show', $regla) }}" class="font-medium text-indigo-600 hover:underline">{{ $regla->nombre }}</a>
                                            <p class="text-xs text-gray-500">
                                                {{ $regla->beneficiario }}
                                                @if ($regla->ambito === 'personal')
                                                    · <span class="text-purple-700">Personal</span>
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-4 py-2.5 text-gray-600">{{ $calendario->enPalabras($regla->calendario()) }}</td>
                                        <td class="px-4 py-2.5 tabular-nums text-gray-700">
                                            @if ($regla->monto_modo === 'fijo')
                                                {{ $regla->moneda }} {{ Dinero::mostrar($regla->importe) }}
                                            @else
                                                <span class="text-amber-700">Cambia cada vez</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $claseEstado($regla->estado) }}">{{ Regla::ESTADOS[$regla->estado] }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-gray-600">{{ $regla->generadas }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{ $reglas->links() }}
            @endif
        </div>
    </div>
</x-app-layout>
