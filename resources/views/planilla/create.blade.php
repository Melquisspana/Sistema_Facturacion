@php
    use App\Models\Planilla\Planilla;

    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Generar una planilla</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Elegir el tipo recarga la pantalla para ofrecer los períodos que
                 corresponden: una semana y una quincena no son la misma lista. Se paga por
                 quincena, así que viene marcada y los otros tipos quedan cerrados. --}}
            <details class="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm" @if ($tipo !== 'quincenal') open @endif>
                <summary class="cursor-pointer text-gray-700">Pago {{ mb_strtolower(Planilla::TIPOS_PERIODO[$tipo]) }} · <span class="text-indigo-600">cambiar</span></summary>
                <nav class="mt-2 flex flex-wrap gap-1" aria-label="Tipo de período">
                    @foreach (Planilla::TIPOS_PERIODO as $clave => $texto)
                        <a href="{{ route('planilla.create', ['tipo' => $clave]) }}"
                           @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => $tipo === $clave, 'text-gray-600 hover:bg-gray-50' => $tipo !== $clave])>{{ $texto }}</a>
                    @endforeach
                </nav>
            </details>

            {{-- GET: elegir no crea nada. Lo que se crea pasa por la revisión. --}}
            <form method="GET" action="{{ route('planilla.revisar') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
                <input type="hidden" name="tipo_periodo" value="{{ $tipo }}">

                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">¿Qué período?</legend>
                    <p class="text-xs text-gray-500">
                        Solo se ofrecen períodos ya transcurridos o el actual: una planilla se prepara sobre
                        tiempo trabajado.
                    </p>
                    <div class="mt-2 space-y-1">
                        @foreach ($periodos as $i => $p)
                            <label class="flex min-h-11 items-center gap-2 rounded border border-gray-200 px-3 text-sm text-gray-700 hover:bg-gray-50">
                                <input type="radio" name="fecha" value="{{ $p['desde'] }}" @checked($i === 0)
                                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span>{{ $p['etiqueta'] }}</span>
                                <span class="ml-auto text-xs text-gray-400">{{ $p['periodo'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="clase" class="block text-sm font-medium text-gray-700">Tipo</label>
                        <select id="clase" name="clase" class="{{ $c }}">
                            @foreach ($clases as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('clase', 'regular') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Con una sola moneda no hay nada que elegir. --}}
                    @if (count(config('gastos.monedas')) > 1)
                        <div>
                            <label for="moneda" class="block text-sm font-medium text-gray-700">Moneda</label>
                            <select id="moneda" name="moneda" class="{{ $c }}">
                                @foreach (config('gastos.monedas') as $moneda)
                                    <option value="{{ $moneda }}">{{ $moneda }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="moneda" value="{{ config('gastos.monedas')[0] }}">
                    @endif
                    <div>
                        <label for="fecha_pago" class="block text-sm font-medium text-gray-700">Fecha de pago</label>
                        <input id="fecha_pago" name="fecha_pago" type="date" value="{{ old('fecha_pago') }}" class="{{ $c }}">
                    </div>
                </div>

                <p class="text-xs text-gray-500">
                    Aguinaldo y Quincena 25 usan la <strong>fecha de pago</strong> en vez del período de arriba, y
                    calculan solos el monto de cada persona, con y sin descuentos de ley. Las vacaciones se pagan de
                    a una persona, en <a href="{{ route('planilla.vacaciones') }}" class="underline">Pagar vacaciones</a>.
                </p>

                <div>
                    <label for="observaciones" class="block text-sm font-medium text-gray-700">Observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="2" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">{{ old('observaciones') }}</textarea>
                </div>

                <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                    Entra cada persona activa con su sueldo y sus descuentos de ley. Antes de crear nada te
                    mostramos cuántas personas son y el total, para que lo confirmes una sola vez.
                </p>

                <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                    <a href="{{ route('planilla.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">Generar</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
