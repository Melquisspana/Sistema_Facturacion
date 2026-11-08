@php
    use App\Models\Planilla\Planilla;
    use App\Services\Gastos\Dinero;

    $d = $totales->enDecimal($resumen);
    $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            {{ $planilla->esVacaciones() ? $planilla->nombreDeVacaciones() : 'Generar la planilla '.$planilla->periodo }}
        </h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <p class="font-medium">No se generó nada.</p>
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($planilla->esVacaciones())
                {{-- Vacaciones: anual y de una persona. Sin tipo de período ni mes: el año de
                     servicio, la fecha de pago y el monto con sus descuentos. --}}
                @php($detalle = $planilla->detalles->first())
                <section class="rounded-lg border border-indigo-200 bg-indigo-50 p-4" aria-labelledby="que-se-crea">
                    <h2 id="que-se-crea" class="text-sm font-semibold text-indigo-900">Se va a generar</h2>
                    <p class="mt-1 text-sm text-indigo-900">
                        {{ $planilla->periodoEnPalabras() }}
                        @if ($planilla->fecha_pago) · Pago el {{ $planilla->fecha_pago->format('d/m/Y') }} @endif
                    </p>

                    @if ($detalle)
                        <dl class="mt-3 space-y-1 rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm">
                            @foreach ($detalle->conceptos->where('tipo', 'ingreso') as $ingreso)
                                <div class="flex justify-between gap-4"><dt class="text-gray-700">{{ $ingreso->concepto }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $planilla->moneda }} {{ Dinero::mostrar(Dinero::centavos((string) $ingreso->importe)) }}</dd></div>
                            @endforeach
                            {{-- Una renta en cero no dice nada: como en el recibo, no se muestra. --}}
                            @foreach ($detalle->conceptos->where('tipo', 'descuento')->reject(fn ($c) => $c->ley !== null && Dinero::centavos((string) $c->importe) === 0) as $descuento)
                                <div class="flex justify-between gap-4 pl-4 text-gray-600"><dt>− {{ $descuento->concepto }}</dt><dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar(Dinero::centavos((string) $descuento->importe)) }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-4 border-t border-gray-200 pt-1 font-semibold"><dt>A pagar</dt><dd class="whitespace-nowrap tabular-nums text-indigo-900">{{ $planilla->moneda }} {{ $d['a_pagar'] }}</dd></div>
                        </dl>
                    @endif

                    <p class="mt-3 text-sm text-indigo-900">
                        Queda lo que se le debe a la persona y a los terceros, y sale el recibo para imprimir y firmar.
                    </p>
                </section>
            @else
            {{-- Toca dinero: la confirmación va en la pantalla y dice qué queda, con los datos del caso. --}}
            <section class="rounded-lg border border-indigo-200 bg-indigo-50 p-4" aria-labelledby="que-se-crea">
                <h2 id="que-se-crea" class="text-sm font-semibold text-indigo-900">Se va a generar</h2>
                <p class="mt-1 text-sm text-indigo-900">
                    {{ Planilla::TIPOS_PERIODO[$planilla->tipo_periodo] }}
                    @if ($planilla->clase !== 'regular') · {{ Planilla::CLASES[$planilla->clase] }} @endif
                    · {{ $planilla->periodoEnPalabras() }}
                    @if ($planilla->fecha_pago) · pago el {{ $planilla->fecha_pago->format('d/m/Y') }} @endif
                </p>

                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-md border border-indigo-200 bg-white px-3 py-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Personas</dt>
                        <dd class="text-2xl font-bold tabular-nums text-gray-900">{{ count($personas) }}</dd>
                    </div>
                    <div class="rounded-md border border-indigo-200 bg-white px-3 py-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total a pagar · {{ $planilla->moneda }}</dt>
                        <dd class="text-2xl font-bold tabular-nums text-indigo-900">{{ $d['a_pagar'] }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-sm text-indigo-900">
                    Queda lo que se le debe a cada persona y a los terceros, y sale la hoja para imprimir y firmar.
                    Si hay que corregirla, se puede borrar mientras sea la última.
                </p>
            </section>
            @endif

            @if ($avisos !== [])
                <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900" role="status">
                    <p class="font-medium">Para revisar antes de generar:</p>
                    @include('planilla.partials.avisos-por-persona', ['lista' => $avisos])
                </div>
            @endif

            @if ($reparos !== [])
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                    <p class="font-medium">Antes de generar hay que resolver:</p>
                    @include('planilla.partials.avisos-por-persona', ['lista' => $reparos])
                </div>
            @endif

            @unless ($planilla->esVacaciones())
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white" aria-labelledby="personas-a-generar">
                <h2 id="personas-a-generar" class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Quién entra</h2>
                <ul class="divide-y divide-gray-100">
                    @forelse ($personas as $persona)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="block font-medium text-gray-900">{{ $persona['nombre'] }}</span>
                                @if ($persona['nota'] ?? null)
                                    <span class="block text-xs text-amber-700">{{ $persona['nota'] }}</span>
                                @endif
                            </span>
                            <span class="whitespace-nowrap tabular-nums text-gray-900">{{ $planilla->moneda }} {{ Dinero::mostrar($persona['a_pagar']) }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-gray-600">No hay personas activas para este período.</li>
                    @endforelse
                </ul>
            </section>
            @endunless

            <form method="POST" action="{{ route('planilla.store') }}"
                  class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                @csrf
                @foreach ($datos as $campo => $valor)
                    <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                @endforeach
                <a href="{{ $planilla->esVacaciones() ? route('planilla.vacaciones') : route('planilla.create', ['tipo' => $datos['tipo_periodo'] ?? null]) }}" class="{{ $secundario }}">Cancelar</a>
                @if ($reparos === [])
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ $planilla->esVacaciones() ? 'Sí, generar el pago' : 'Sí, generar la planilla' }}
                    </button>
                @endif
            </form>
        </div>
    </div>
</x-app-layout>
