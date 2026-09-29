@php
    use App\Models\Gastos\PreferenciaAvisos;
    use App\Models\Gastos\Resumen;
    use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;

    $control = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $dias = old('dias_anticipacion', $prefs->dias_anticipacion ?? [7, 3, 0]);
    $ambitos = old('ambitos', $prefs->ambitos ?? ['empresarial']);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Preferencias de avisos</h1>
            <a href="{{ route('gastos.avisos.index') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a la bandeja</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-4">
            <x-gastos-aviso />
            <x-correo-simulado-aviso />

            {{-- Vista previa REAL, calculada ahora. Sirve para que nadie encienda el
                 correo esperando algo que no va a llegar: si esto dice cero, no hay
                 resumen, y eso es correcto —no se manda un correo para decir que no hay
                 nada—. --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Qué tendría tu resumen hoy</h2>
                @if ($previa['obligaciones'] === 0)
                    <p class="mt-2 text-sm text-gray-600">
                        <strong>Nada pendiente.</strong> Con este estado no se te enviaría ningún correo:
                        los resúmenes solo salen cuando hay pendientes reales.
                    </p>
                @else
                    <p class="mt-2 text-sm text-gray-700">
                        {{ $previa['obligaciones'] }} obligación(es):
                        <strong class="text-red-700">{{ count($previa['vencidos']) }} vencida(s)</strong>,
                        {{ count($previa['proximos']) }} por vencer y
                        {{ count($previa['sin_monto']) }} esperando monto.
                    </p>
                    @if ($previa['totales'] !== [])
                        <p class="mt-1 text-sm text-gray-600">
                            Pendiente:
                            @foreach ($previa['totales'] as $moneda => $total)
                                <span class="font-semibold tabular-nums">{{ $moneda }} {{ $total }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                @endif
            </div>

            <form method="POST" action="{{ route('gastos.avisos.preferencias.guardar') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Bandeja interna</h2>

                    <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="activo" value="1" @checked(old('activo', $prefs->activo))
                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Recibir avisos en la bandeja</span>
                    </label>

                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700">Avisarme con esta anticipación</legend>
                        <p class="text-xs text-gray-500">Días antes del vencimiento. «0» es el mismo día que vence.</p>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ([0, 1, 3, 7, 15, 30] as $d)
                                <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="dias_anticipacion[]" value="{{ $d }}"
                                           @checked(in_array($d, array_map('intval', (array) $dias), true))
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span>{{ $d === 0 ? 'El día' : $d.' días' }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="block text-sm font-medium text-gray-700">Ámbitos</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="ambitos[]" value="empresarial"
                                       @checked(in_array('empresarial', (array) $ambitos, true))
                                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span>De la empresa</span>
                            </label>

                            @if ($puedePersonales)
                                <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="ambitos[]" value="personal"
                                           @checked(in_array('personal', (array) $ambitos, true))
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span>Personales</span>
                                </label>
                            @endif
                        </div>
                        @unless ($puedePersonales)
                            <p class="mt-1 text-xs text-gray-500">
                                Los gastos personales no aparecen entre tus opciones porque no tenés alcance sobre ellos.
                            </p>
                        @endunless
                    </fieldset>
                </section>

                <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Resumen por correo</h2>

                    <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="correo" value="1" @checked(old('correo', $prefs->correo))
                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Mandarme el resumen por correo</span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="resumen" class="block text-sm font-medium text-gray-700">Cada cuánto</label>
                            <select id="resumen" name="resumen" class="{{ $control }}">
                                @foreach (PreferenciaAvisos::RESUMENES as $clave => $texto)
                                    <option value="{{ $clave }}" @selected(old('resumen', $prefs->resumen) === $clave)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="resumen_dia_semana" class="block text-sm font-medium text-gray-700">Qué día (si es semanal)</label>
                            <select id="resumen_dia_semana" name="resumen_dia_semana" class="{{ $control }}">
                                @foreach (CalendarioRecurrencia::DIAS_SEMANA as $n => $texto)
                                    <option value="{{ $n }}" @selected((int) old('resumen_dia_semana', $prefs->resumen_dia_semana) === $n)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                        <strong>Nunca se manda un correo vacío.</strong> Si al momento de armarlo no hay pendientes,
                        no se envía nada y no queda registro de envío. Tampoco se manda un correo por cada pago ni
                        avisos de que todo salió bien.
                    </p>
                </section>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Guardar preferencias
                    </button>
                </div>
            </form>

            @if ($ultimos->isNotEmpty())
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Últimos resúmenes</h2>
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($ultimos as $r)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="text-gray-700">
                                    {{ $r->ventana }} · {{ $r->obligaciones }} obligación(es)
                                </span>
                                <span @class([
                                    'text-xs font-medium',
                                    'text-emerald-700' => $r->estado === 'enviado',
                                    'text-sky-700' => $r->estado === 'simulado',
                                    'text-red-700' => $r->estado === 'fallido',
                                    'text-gray-500' => $r->estado === 'preparado',
                                ])>{{ Resumen::ESTADOS[$r->estado] ?? $r->estado }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">
                        «Simulado» significa que el sistema NO lo envió porque el entorno no lo permite. No es un error,
                        y tampoco quiere decir que haya llegado.
                    </p>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
