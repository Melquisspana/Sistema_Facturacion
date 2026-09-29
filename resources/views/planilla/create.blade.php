@php
    use App\Models\Planilla\Planilla;

    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Preparar una planilla</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- Elegir el tipo recarga la pantalla para ofrecer los períodos que
                 corresponden: una semana y una quincena no son la misma lista. --}}
            <nav class="flex flex-wrap gap-1 rounded-md border border-gray-200 bg-white p-1" aria-label="Tipo de período">
                @foreach (Planilla::TIPOS_PERIODO as $clave => $texto)
                    <a href="{{ route('planilla.create', ['tipo' => $clave]) }}"
                       @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => $tipo === $clave, 'text-gray-600 hover:bg-gray-50' => $tipo !== $clave])>{{ $texto }}</a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('planilla.store') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
                @csrf
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
                            @foreach (Planilla::CLASES as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('clase', 'regular') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="moneda" class="block text-sm font-medium text-gray-700">Moneda</label>
                        <select id="moneda" name="moneda" class="{{ $c }}">
                            @foreach (config('gastos.monedas') as $moneda)
                                <option value="{{ $moneda }}">{{ $moneda }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="fecha_pago" class="block text-sm font-medium text-gray-700">Fecha de pago</label>
                        <input id="fecha_pago" name="fecha_pago" type="date" value="{{ old('fecha_pago') }}" class="{{ $c }}">
                    </div>
                </div>

                <div>
                    <label for="observaciones" class="block text-sm font-medium text-gray-700">Observaciones</label>
                    <textarea id="observaciones" name="observaciones" rows="2" maxlength="2000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm">{{ old('observaciones') }}</textarea>
                </div>

                <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                    Se abre un <strong>borrador</strong>. No crea ninguna obligación ni nada que pagar; eso
                    ocurre al confirmar. Si ya existe la planilla de ese período, se abre esa: no se duplica.
                </p>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">Abrir el borrador</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
