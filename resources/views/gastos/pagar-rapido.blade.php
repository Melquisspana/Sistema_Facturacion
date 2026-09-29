@php
    use App\Services\Gastos\Dinero;

    $campo = 'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 min-h-11';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

{{-- Pago rápido de un pendiente.

     Se entra DESDE la obligación, así que el sistema ya sabe a quién, cuánto y por qué.
     Quedan tres preguntas: cuándo, cuánto y cómo —y las dos primeras vienen
     precargadas—.

     Lo que no se pregunta: destinatario, moneda, concepto, categoría, a qué cuota
     aplicar y quién pagó. Todo eso ya está registrado o se deduce de la sesión. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Registrar pago</h1>
            <a href="{{ route('gastos.show', $gasto) }}" class="{{ $boton }}">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-2xl space-y-3">

            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- El contexto: lo que el sistema YA sabe. Se muestra para confirmar que se
                 está pagando lo correcto, no para volver a escribirlo. --}}
            <section class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $gasto->beneficiario }} · {{ $gasto->concepto }}</p>
                        <p class="mt-0.5 text-sm text-gray-600">
                            {{ $gasto->categoria }} ·
                            {{ $gasto->ambito === 'personal' ? 'personal' : 'de la empresa' }}
                            @if ($cuotas->count() === 1 && $cuotas[0]['vence'])
                                · vence el {{ $cuotas[0]['vence'] }}
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Pendiente</p>
                        <p class="text-xl font-bold tabular-nums text-emerald-800">
                            {{ $gasto->moneda }} {{ Dinero::mostrar($pendiente) }}
                        </p>
                    </div>
                </div>
                <p class="mt-3 border-t border-emerald-200 pt-2 text-xs italic text-gray-500">
                    Todo esto ya está registrado. No se vuelve a preguntar.
                </p>
            </section>

            <form method="POST" action="{{ route('gastos.pagos.store') }}"
                  class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="pagado_por" value="{{ auth()->id() }}">

                {{-- Una cuota: se aplica sola y no se pregunta nada. Varias: hay que
                     decir cuánto va a cada una, porque repartirlo por nuestra cuenta
                     sería decidir sobre el dinero de alguien más. --}}
                {{-- El nombre del campo NO es decorativo: `PagoController::store` espera
                     `aplicar[cuota_id] => importe`, un mapa. Cualquier otra forma llega
                     como un array que la validación rechaza, y el formulario vuelve con
                     un error que no señala a ningún campo visible. --}}
                @if ($cuotas->count() === 1)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha del pago</label>
                            <input id="fecha" name="fecha" type="date" required max="{{ $hoy }}"
                                   value="{{ old('fecha', $hoy) }}" class="{{ $campo }} mt-1">
                            <p class="mt-1 text-xs text-emerald-700">Hoy. Se puede cambiar.</p>
                        </div>
                        <div>
                            <label for="importe" class="block text-sm font-medium text-gray-700">Importe</label>
                            <input id="importe" name="importe" type="text" inputmode="decimal" required
                                   value="{{ old('importe', Dinero::decimal($pendiente)) }}"
                                   oninput="document.getElementById('aplica-unica').value = this.value"
                                   class="{{ $campo }} mt-1 text-right text-lg tabular-nums">
                            {{-- Con una sola cuota, lo que se escribe arriba es lo que se
                                 aplica: el campo oculto lo sigue para no preguntarlo dos
                                 veces. --}}
                            <input type="hidden" id="aplica-unica" name="aplicar[{{ $cuotas[0]['cuota_id'] }}]"
                                   value="{{ old('importe', Dinero::decimal($pendiente)) }}">
                            <p class="mt-1 text-xs text-emerald-700">Lo que se debe. Si pagaste menos, queda el saldo.</p>
                        </div>
                    </div>
                @else
                    <div>
                        <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha del pago</label>
                        <input id="fecha" name="fecha" type="date" required max="{{ $hoy }}"
                               value="{{ old('fecha', $hoy) }}" class="{{ $campo }} mt-1 sm:max-w-xs">
                    </div>
                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700">¿Cuánto a cada cuota?</legend>
                        <div class="mt-2 space-y-2">
                            @foreach ($cuotas as $i => $c)
                                <div class="flex items-center gap-3 rounded-md border border-gray-200 p-2">
                                    <span class="flex-1 text-sm text-gray-700">
                                        Cuota {{ $c['numero'] }}@if ($c['vence']) · vence {{ $c['vence'] }}@endif
                                        <span class="block text-xs text-gray-500">pendiente {{ Dinero::mostrar($c['pendiente']) }}</span>
                                    </span>
                                    <input type="text" inputmode="decimal" name="aplicar[{{ $c['cuota_id'] }}]"
                                           value="{{ Dinero::decimal($c['pendiente']) }}"
                                           class="w-32 rounded-md border-gray-300 text-right tabular-nums">
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3">
                            <label for="importe" class="block text-sm font-medium text-gray-700">Total del pago</label>
                            <input id="importe" name="importe" type="text" inputmode="decimal" required
                                   value="{{ old('importe', Dinero::decimal($pendiente)) }}"
                                   class="{{ $campo }} mt-1 text-right text-lg tabular-nums sm:max-w-xs">
                        </div>
                    </fieldset>
                @endif

                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700">¿Cómo pagaste?</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (config('gastos.metodos') as $clave => $texto)
                            <label class="cursor-pointer">
                                <input type="radio" name="metodo" value="{{ $clave }}" class="peer sr-only"
                                       @checked(old('metodo', 'transferencia') === $clave)>
                                <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                             peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800">
                                    {{ $texto }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <details class="rounded-md border border-gray-200 bg-gray-50 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-700">
                        Referencia y comprobante <span class="font-normal text-gray-500">· opcionales</span>
                    </summary>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="referencia" class="block text-xs text-gray-600">Número de transferencia o recibo</label>
                            <input id="referencia" name="referencia" type="text" maxlength="180"
                                   value="{{ old('referencia') }}" class="{{ $campo }} mt-1">
                        </div>
                        <div>
                            <label for="sin_comprobante" class="block text-xs text-gray-600">¿No hay comprobante?</label>
                            <input id="sin_comprobante" name="sin_comprobante" type="text" maxlength="250"
                                   value="{{ old('sin_comprobante') }}" placeholder="Explicá por qué"
                                   class="{{ $campo }} mt-1">
                        </div>
                    </div>
                </details>

                <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('gastos.show', $gasto) }}" class="{{ $boton }}">Cancelar</a>
                    <button type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-6 text-sm font-semibold text-white hover:bg-indigo-700">
                        Registrar pago
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
