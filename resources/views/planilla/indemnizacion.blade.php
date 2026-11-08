{{-- «Calcular indemnización» o «Calcular prestación por renuncia», desde la ficha de quien
     se fue. No guarda nada: muestra el cálculo y lleva a la revisión del pago, la misma de
     vacaciones, que es donde se genera. Sin período ni quincena: es el pago de una persona. --}}
@php
    use App\Services\Gastos\Dinero;
    use App\Services\Planilla\IndemnizacionBaja;
    use Illuminate\Support\Str;

    $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    $campo = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $c = $calculo;
    $listo = $c !== null && $c['fuera'] === null && $c['falta'] === null;
    $nombre = $motivo !== null && isset(IndemnizacionBaja::CLASES[$motivo]) ? IndemnizacionBaja::nombre($motivo) : 'indemnización o prestación por renuncia';
    $ficha = route('personal.empleados.show', 'p'.$empleado->id);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Calcular {{ $nombre }} de {{ $empleado->nombre }}</h1>
            <a href="{{ $ficha }}" class="{{ $secundario }}">Volver a la ficha</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <p class="font-medium">No se generó ningún pago.</p>
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($elegir)
                {{-- Bajas de antes de que se preguntara el motivo: se elige acá para calcular. --}}
                <form method="GET" action="{{ route('planilla.empleados.indemnizacion', $empleado) }}" class="rounded-lg border border-gray-200 bg-white p-4">
                    <label for="motivo" class="block text-sm font-medium text-gray-700">Motivo de la baja</label>
                    <select id="motivo" name="motivo" required aria-describedby="motivo_ayuda" class="{{ $campo }}">
                        <option value="">Elegí el motivo</option>
                        @foreach (IndemnizacionBaja::MOTIVOS as $clave => $texto)
                            <option value="{{ $clave }}" @selected($motivo === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    <p id="motivo_ayuda" class="mt-1 text-xs text-gray-500">Esta baja se registró sin motivo. Elegilo para ver lo que le toca.</p>
                    <button type="submit" class="{{ $secundario }} mt-3">Calcular</button>
                </form>
            @endif

            @if ($c !== null && $c['fuera'] !== null)
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">
                    <p class="font-medium">No se calcula.</p>
                    <p>{{ Str::ucfirst($c['fuera']) }}.</p>
                </div>
            @elseif ($c !== null && $c['falta'] !== null)
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="alert">
                    <p class="font-medium">Falta {{ $c['falta'] }} para calcularlo.</p>
                    <p>Completalo en la ficha de la persona y volvé a esta pantalla.</p>
                </div>
            @elseif ($listo)
                @php($regla = $c['regla'])
                <section class="rounded-lg border border-indigo-200 bg-indigo-50 p-4" aria-labelledby="el-calculo">
                    <h2 id="el-calculo" class="text-sm font-semibold text-indigo-900">El cálculo</h2>

                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Motivo de la baja</dt>
                            <dd class="text-gray-900">{{ IndemnizacionBaja::MOTIVOS[$motivo] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Años trabajados</dt>
                            <dd class="text-gray-900">
                                {{ $c['tiempo']['texto'] }} <span class="tabular-nums">({{ $c['anios_decimal'] }} años)</span>
                                <span class="block text-xs text-gray-500">del {{ $c['desde']->format('d/m/Y') }} al {{ $c['hasta']->format('d/m/Y') }}, el día de baja incluido</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Sueldo mensual a la fecha de baja</dt>
                            <dd class="tabular-nums text-gray-900">{{ $moneda }} {{ Dinero::mostrar($c['mensual']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Salario diario usado</dt>
                            <dd class="tabular-nums text-gray-900">
                                {{ $c['diario_usado'] }}
                                @if ($c['con_tope'])
                                    <span class="block text-xs text-amber-800">Se aplicó el tope de {{ $regla['tope_minimos'] }} salarios mínimos diarios. El suyo es {{ $c['diario'] }} ({{ Dinero::decimal($c['mensual']) }} ÷ 30).</span>
                                @else
                                    <span class="block text-xs text-gray-500">{{ Dinero::decimal($c['mensual']) }} ÷ 30. No pasa el tope de {{ $regla['tope_minimos'] }} salarios mínimos diarios ({{ $c['tope_diario'] }}).</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Días que se pagan</dt>
                            <dd class="tabular-nums text-gray-900">
                                @if ($c['al_minimo'])
                                    {{ $regla['minimo_dias'] }}
                                    <span class="block text-xs text-amber-800">Le tocaban {{ $c['dias_pagados'] }} días ({{ $regla['dias_por_anio'] }} por año): se paga el mínimo de {{ $regla['minimo_dias'] }}.</span>
                                @else
                                    {{ $c['dias_pagados'] }}
                                    <span class="block text-xs text-gray-500">{{ $regla['dias_por_anio'] }} días por año × {{ $c['anios_decimal'] }} años</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Descuentos</dt>
                            <dd class="text-gray-900">{{ $conDescuentos ? 'Lleva ISSS, AFP y renta según la ficha' : 'Sin ISSS, AFP ni renta' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 rounded-md border border-gray-200 bg-white px-3 py-2">
                        <p class="text-xs uppercase tracking-wide text-gray-500">{{ $c['concepto'] }}</p>
                        <p class="text-2xl font-bold tabular-nums text-indigo-900">{{ $moneda }} {{ Dinero::mostrar($c['centavos']) }}</p>
                        <p class="text-xs text-gray-500">Salario diario × días que se pagan, redondeado una sola vez al centavo.</p>
                    </div>

                    <p class="mt-3 text-sm text-indigo-900">
                        Es el pago de esta sola persona, una vez por baja. Antes de generarlo se revisa, y sale el recibo para firmar.
                    </p>
                </section>

                {{-- GET: no crea nada, lleva a la revisión del pago, que es donde se genera. --}}
                <form method="GET" action="{{ route('planilla.revisar') }}" class="space-y-3">
                    <input type="hidden" name="tipo_periodo" value="mensual">
                    <input type="hidden" name="clase" value="{{ IndemnizacionBaja::CLASES[$motivo] }}">
                    <input type="hidden" name="fecha" value="{{ $c['hasta']->toDateString() }}">
                    <input type="hidden" name="moneda" value="{{ $moneda }}">
                    <input type="hidden" name="empleado" value="{{ $empleado->id }}">
                    <div class="max-w-xs">
                        <label for="fecha_pago" class="block text-sm font-medium text-gray-700">Fecha de pago</label>
                        <input id="fecha_pago" name="fecha_pago" type="date" value="{{ old('fecha_pago', $hoy) }}"
                               min="{{ $c['hasta']->toDateString() }}" max="{{ $maxima }}" required class="{{ $campo }}">
                    </div>
                    <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ $ficha }}" class="{{ $secundario }}">Cancelar</a>
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                            Revisar el pago
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
