{{-- El cálculo de la indemnización o de la prestación por renuncia, antes de generarla.
     Mismo molde que generar.blade.php: toca dinero, así que la confirmación va en la pantalla. --}}
@php
    use App\Services\Gastos\Dinero;
    use App\Services\Planilla\IndemnizacionBaja;
    use Illuminate\Support\Str;

    $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    $campo = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $c = $calculo;
    $listo = $c !== null && $c['fuera'] === null && $c['falta'] === null;
    $nombre = $motivo === null || $motivo === 'otro' ? 'indemnización o prestación por renuncia' : IndemnizacionBaja::nombre($motivo);
    $moneda = config('gastos.monedas')[0];
    $ficha = route('personal.empleados.show', 'p'.$empleado->id);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ Str::ucfirst($nombre) }} · {{ $empleado->nombre }}</h1>
            <a href="{{ $ficha }}" class="{{ $secundario }}">Volver a la ficha</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-3">
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <p class="font-medium">No se generó nada.</p>
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($elegido)
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
                    <p>{{ $c['fuera'] }}</p>
                </div>
            @elseif ($c !== null && $c['falta'] !== null)
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" role="alert">
                    <p class="font-medium">Falta {{ $c['falta'] }} para calcularlo.</p>
                    <p>Completalo en la ficha de la persona y volvé a esta pantalla.</p>
                </div>
            @elseif ($listo)
                @php($regla = $c['regla'])
                <section class="rounded-lg border border-gray-200 bg-indigo-50 p-4" aria-labelledby="el-calculo">
                    <h2 id="el-calculo" class="text-sm font-semibold text-indigo-900">El cálculo</h2>

                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Motivo</dt>
                            <dd class="text-gray-900">{{ IndemnizacionBaja::MOTIVOS[$motivo] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Tiempo de servicio</dt>
                            <dd class="text-gray-900">
                                {{ $c['tiempo']['texto'] }} <span class="tabular-nums">({{ $c['anios_decimal'] }} años)</span>
                                <span class="block text-xs text-gray-500">del {{ $c['desde']->format('d/m/Y') }} al {{ $c['hasta']->format('d/m/Y') }}, ese día incluido</span>
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
                        <p class="text-xs uppercase tracking-wide text-gray-500">{{ Str::ucfirst($nombre) }} · {{ $moneda }}</p>
                        <p class="text-2xl font-bold tabular-nums text-indigo-900">{{ Dinero::mostrar($c['centavos']) }}</p>
                        <p class="text-xs text-gray-500">Salario diario × días que se pagan, redondeado una sola vez al centavo.</p>
                    </div>

                    <p class="mt-3 text-sm text-indigo-900">
                        Queda una planilla de esta sola persona, con lo que se le debe y el recibo para firmar.
                        Se genera una sola vez por baja.
                    </p>
                </section>

                <form method="POST" action="{{ route('planilla.empleados.indemnizacion.store', $empleado) }}" class="space-y-3">
                    @csrf
                    @if ($elegido)
                        <input type="hidden" name="motivo" value="{{ $motivo }}">
                    @endif
                    <div class="max-w-xs">
                        <label for="fecha_pago" class="block text-sm font-medium text-gray-700">Fecha de pago</label>
                        <input id="fecha_pago" name="fecha_pago" type="date" value="{{ old('fecha_pago', $hoy) }}" required class="{{ $campo }}">
                    </div>
                    <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
                        <a href="{{ $ficha }}" class="{{ $secundario }}">Cancelar</a>
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                            Sí, generar la {{ $nombre }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
