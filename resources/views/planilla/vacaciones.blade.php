@php
    use App\Services\Gastos\Dinero;

    $campo = 'block min-h-11 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Pagar vacaciones</h1>
            <a href="{{ route('planilla.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a Planillas</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <p class="text-sm text-gray-600">
                Las vacaciones se pagan de a una persona: 15 días de salario más 30 %, por el último año de servicio
                cumplido. Elegí la fecha de pago y revisá el pago antes de generarlo. El neto es una estimación.
            </p>

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="sr-only">Vacaciones por pagar</caption>
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">Persona</th>
                                <th scope="col" class="px-4 py-2 font-medium">Año de servicio</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Bruto</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Neto estimado</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($filas as $f)
                                @php($e = $f['empleado'])
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <p class="font-medium text-gray-900">{{ $e->nombre }}</p>
                                        <p class="text-xs text-gray-500">{{ $e->cargo ?: 'Sin cargo' }}</p>
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-700">
                                        del {{ $f['desde']->format('d/m/Y') }} al {{ $f['aniversario']->format('d/m/Y') }}
                                        @unless ($e->con_descuentos_ley)
                                            <span class="block text-xs text-gray-500">Sin descuentos de ley</span>
                                        @endunless
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-gray-700">
                                        {{ $f['bruto'] === null ? 'Falta el sueldo' : $moneda.' '.Dinero::mostrar($f['bruto']) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums text-gray-900">
                                        {{ $f['neto'] === null ? '—' : $moneda.' '.Dinero::mostrar($f['neto']) }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        {{-- Distinta a propósito: cada renglón es su propio pago, con su fecha y su
                                             botón. GET: no crea nada, lleva a la revisión del pago. --}}
                                        <form method="GET" action="{{ route('planilla.revisar') }}" class="flex flex-wrap items-center justify-end gap-2">
                                            <input type="hidden" name="tipo_periodo" value="mensual">
                                            <input type="hidden" name="clase" value="vacaciones">
                                            <input type="hidden" name="fecha" value="{{ $hoy }}">
                                            <input type="hidden" name="moneda" value="{{ $moneda }}">
                                            <input type="hidden" name="empleado" value="{{ $e->id }}">
                                            <label for="fecha-pago-{{ $e->id }}" class="sr-only">Fecha de pago de {{ $e->nombre }}</label>
                                            <input id="fecha-pago-{{ $e->id }}" name="fecha_pago" type="date" value="{{ $hoy }}" required class="{{ $campo }}">
                                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Pagar vacaciones</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-10 text-center text-gray-500">No hay vacaciones por pagar.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
