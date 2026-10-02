@php
    use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;

    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    // Valores propuestos: si el gasto ya tiene vencimiento, se propone ese día. Nada
    // de esto se vuelve a preguntar si ya está escrito en el gasto.
    $diaPropuesto = $diaPropuesto ?? (int) now()->format('d');
    $mesPropuesto = $mesPropuesto ?? (int) now()->format('m');
    $diaSemanaPropuesto = $diaSemanaPropuesto ?? (int) now()->dayOfWeekIso;
    $modoPropuesto = $modoPropuesto ?? 'fijo';
@endphp

{{--
    Los campos de «se repite». Compartidos por el alta y por la ficha de un gasto que
    ya existe, para que no haya dos formularios que validen cosas distintas.

    NO se pregunta el importe. Sale del propio gasto: es la diferencia entre
    «configurar una repetición» y «volver a capturar el gasto».
--}}
<div x-data="{ frecuencia: @js(old($prefijo.'.frecuencia', 'mensual')) }" class="grid gap-3 sm:grid-cols-2">

    <div>
        <label for="{{ $prefijo }}-frecuencia" class="block text-sm font-medium text-gray-700">¿Cada cuánto?</label>
        <select id="{{ $prefijo }}-frecuencia" name="{{ $prefijo }}[frecuencia]" x-model="frecuencia" class="{{ $c }}">
            @foreach (CalendarioRecurrencia::FRECUENCIAS as $clave => $texto)
                <option value="{{ $clave }}" @selected(old($prefijo.'.frecuencia', 'mensual') === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
    </div>

    <div x-show="frecuencia === 'semanal'" x-cloak>
        <label for="{{ $prefijo }}-dia-semana" class="block text-sm font-medium text-gray-700">¿Qué día?</label>
        <select id="{{ $prefijo }}-dia-semana" name="{{ $prefijo }}[dia_semana]" class="{{ $c }}">
            @foreach (CalendarioRecurrencia::DIAS_SEMANA as $n => $texto)
                <option value="{{ $n }}" @selected((int) old($prefijo.'.dia_semana', $diaSemanaPropuesto) === $n)>{{ $texto }}</option>
            @endforeach
        </select>
    </div>

    <div x-show="frecuencia === 'anual'" x-cloak>
        <label for="{{ $prefijo }}-mes" class="block text-sm font-medium text-gray-700">¿Qué mes?</label>
        <select id="{{ $prefijo }}-mes" name="{{ $prefijo }}[mes]" class="{{ $c }}">
            @foreach (['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'] as $i => $nombreMes)
                <option value="{{ $i + 1 }}" @selected((int) old($prefijo.'.mes', $mesPropuesto) === $i + 1)>{{ ucfirst($nombreMes) }}</option>
            @endforeach
        </select>
    </div>

    <div x-show="frecuencia !== 'semanal'" x-cloak>
        <label for="{{ $prefijo }}-dia-mes" class="block text-sm font-medium text-gray-700">
            <span x-show="frecuencia === 'quincenal'">Primera fecha del mes</span>
            <span x-show="frecuencia !== 'quincenal'">¿Qué día del mes?</span>
        </label>
        <select id="{{ $prefijo }}-dia-mes" name="{{ $prefijo }}[dia_mes]" class="{{ $c }}">
            @foreach (CalendarioRecurrencia::diasDelMes() as $n => $texto)
                <option value="{{ $n }}" @selected((int) old($prefijo.'.dia_mes', $diaPropuesto) === $n)>{{ $texto }}</option>
            @endforeach
        </select>
    </div>

    <div x-show="frecuencia === 'quincenal'" x-cloak>
        <label for="{{ $prefijo }}-dia-mes-2" class="block text-sm font-medium text-gray-700">Segunda fecha del mes</label>
        <select id="{{ $prefijo }}-dia-mes-2" name="{{ $prefijo }}[dia_mes_2]" class="{{ $c }}">
            @foreach (CalendarioRecurrencia::diasDelMes() as $n => $texto)
                <option value="{{ $n }}" @selected((int) old($prefijo.'.dia_mes_2', CalendarioRecurrencia::ULTIMO_DIA) === $n)>{{ $texto }}</option>
            @endforeach
        </select>
    </div>

    {{-- Quincenal NO es «cada 14 días». Se dice acá, donde se elige, y no en la
         documentación, que nadie abre mientras configura. --}}
    <p x-show="frecuencia === 'quincenal'" x-cloak class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 sm:col-span-2">
        {{ CalendarioRecurrencia::POLITICA_QUINCENAL }}
    </p>

    <fieldset class="sm:col-span-2">
        <legend class="block text-sm font-medium text-gray-700">El importe de las próximas</legend>
        {{-- Se puede elegir «cambia cada vez» aunque ESTE gasto ya tenga monto: el
             recibo de la luz de hoy tiene una cifra concreta y el del mes que viene no
             se sabe. En ese caso los períodos siguientes nacen esperando monto. --}}
        <div class="mt-1 space-y-1">
            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                <input type="radio" name="{{ $prefijo }}[monto_modo]" value="fijo"
                       @checked(old($prefijo.'.monto_modo', $modoPropuesto) === 'fijo')
                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span>Siempre el mismo importe <span class="text-gray-500">(el de este gasto)</span></span>
            </label>
            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                <input type="radio" name="{{ $prefijo }}[monto_modo]" value="variable"
                       @checked(old($prefijo.'.monto_modo', $modoPropuesto) === 'variable')
                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span>Cambia cada vez <span class="text-gray-500">(quedan esperando el recibo)</span></span>
            </label>
        </div>
    </fieldset>

    <div>
        <label for="{{ $prefijo }}-antes" class="block text-sm font-medium text-gray-700">Crearlo con anticipación</label>
        <select id="{{ $prefijo }}-antes" name="{{ $prefijo }}[dias_generar_antes]" class="{{ $c }}">
            @foreach ([0 => 'El mismo día', 3 => '3 días antes', 7 => '7 días antes', 15 => '15 días antes', 30 => '30 días antes'] as $n => $texto)
                <option value="{{ $n }}" @selected((int) old($prefijo.'.dias_generar_antes', 7) === $n)>{{ $texto }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="{{ $prefijo }}-hasta" class="block text-sm font-medium text-gray-700">¿Hasta cuándo? <span class="font-normal text-gray-500">(opcional)</span></label>
        <input id="{{ $prefijo }}-hasta" name="{{ $prefijo }}[vigente_hasta]" type="date"
               value="{{ old($prefijo.'.vigente_hasta') }}" class="{{ $c }}">
    </div>

    <p class="text-xs text-gray-500 sm:col-span-2">
        {{ CalendarioRecurrencia::POLITICA_DIAS }}
    </p>
</div>
