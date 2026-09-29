@php
    use App\Models\Planilla\Planilla;
    use App\Services\Gastos\Dinero;

    $insignia = [
        'borrador' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        'confirmada' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'anulada' => 'bg-red-50 text-red-700 ring-red-600/20',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Planillas</h1>
            @can('planilla.gestionar')
                <a href="{{ route('planilla.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Preparar una planilla</a>
            @endcan
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-3">
            @if (session('planilla.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('planilla.aviso') }}</div>
            @endif

            <p class="rounded-md border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
                Control de remuneraciones con importes revisados a mano. <strong>No es nómina legal</strong>:
                no calcula ISSS, AFP, renta, vacaciones, aguinaldo, indemnización ni horas extra.
            </p>

            @unless ($verImportes)
                <p class="rounded-md border border-sky-200 bg-sky-50 px-4 py-2 text-xs text-sky-900">
                    Ves el estado de cada planilla, pero no los importes: eso exige el permiso de salarios.
                </p>
            @endunless

            @if ($planillas->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                    <p class="text-sm text-gray-600">Todavía no hay ninguna planilla.</p>
                    @can('planilla.gestionar')
                        <a href="{{ route('planilla.create') }}" class="mt-3 inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Preparar la primera</a>
                    @endcan
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Planillas</caption>
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-medium">Período</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Tipo</th>
                                    <th scope="col" class="px-4 py-2 text-right font-medium">Personas</th>
                                    @if ($verImportes)
                                        <th scope="col" class="px-4 py-2 text-right font-medium">A pagar</th>
                                    @endif
                                    <th scope="col" class="px-4 py-2 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($planillas as $p)
                                    @php $t = $verImportes ? $totales->dePlanilla($p) : null; @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2.5">
                                            @can('planilla.gestionar')
                                                <a href="{{ route('planilla.preparar', $p) }}" class="font-medium text-indigo-600 hover:underline">{{ $p->periodo }}</a>
                                            @else
                                                <span class="font-medium text-gray-900">{{ $p->periodo }}</span>
                                            @endcan
                                            <p class="text-xs text-gray-500">{{ $p->periodoEnPalabras() }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-gray-600">
                                            {{ Planilla::TIPOS_PERIODO[$p->tipo_periodo] }}
                                            @if ($p->clase !== 'regular')
                                                <span class="block text-xs text-amber-700">{{ Planilla::CLASES[$p->clase] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-gray-600">{{ $p->detalles_count }}</td>
                                        @if ($verImportes)
                                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-gray-900">
                                                {{ $p->moneda }} {{ Dinero::mostrar($t['a_pagar']) }}
                                            </td>
                                        @endif
                                        <td class="px-4 py-2.5">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $insignia[$p->estado] }}">{{ Planilla::ESTADOS[$p->estado] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{ $planillas->links() }}
            @endif
        </div>
    </div>
</x-app-layout>
