@php
    use App\Models\Gastos\Gasto;
    use App\Models\Gastos\Regla;
    use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;

    $control = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $etiqueta = 'block text-sm font-medium text-gray-700';
    $r = $regla ?? null;
    $v = fn (string $campo, $porDefecto = null) => old($campo, $r?->{$campo} ?? $porDefecto);
@endphp

{{--
    Formulario de una regla recurrente. Compartido por el alta y la edición: si
    fueran dos formularios distintos, tarde o temprano uno validaría algo que el
    otro no.

    El campo de frecuencia gobierna qué otros campos tienen sentido. Se muestran
    TODOS y se habilita el que corresponde con Alpine, en vez de esconderlos: quien
    configura tiene que ver de una que «quincenal» pide dos días del mes y «anual»
    pide mes y día. El servidor vuelve a validar lo mismo, así que la interfaz es
    una ayuda y nunca el candado.
--}}
<div x-data="{ frecuencia: '{{ $v('frecuencia', 'mensual') }}', modo: '{{ $v('monto_modo', 'fijo') }}', ambito: '{{ $v('ambito', 'empresarial') }}' }" class="space-y-6">

    <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Qué se paga</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="nombre" class="{{ $etiqueta }}">Nombre</label>
                <input id="nombre" name="nombre" type="text" maxlength="200" required
                       value="{{ $v('nombre') }}" class="{{ $control }}"
                       placeholder="Alquiler del local">
                <p class="mt-1 text-xs text-gray-500">Cómo la vas a reconocer en la lista. No sale en la obligación.</p>
            </div>

            <div>
                <label for="beneficiario" class="{{ $etiqueta }}">A quién se le paga</label>
                <input id="beneficiario" name="beneficiario" type="text" maxlength="180" required
                       value="{{ $v('beneficiario') }}" class="{{ $control }}">
            </div>

            <div class="sm:col-span-2">
                <label for="concepto" class="{{ $etiqueta }}">Concepto</label>
                <input id="concepto" name="concepto" type="text" maxlength="200" required
                       value="{{ $v('concepto') }}" class="{{ $control }}">
                <p class="mt-1 text-xs text-gray-500">Es el concepto de cada obligación que se genere.</p>
            </div>

            <div>
                <label for="categoria" class="{{ $etiqueta }}">Categoría</label>
                <input id="categoria" name="categoria" type="text" maxlength="100" required
                       value="{{ $v('categoria') }}" class="{{ $control }}" list="categorias-regla">
            </div>

            <div>
                <label for="naturaleza" class="{{ $etiqueta }}">Naturaleza</label>
                <select id="naturaleza" name="naturaleza" class="{{ $control }}">
                    @foreach (Gasto::NATURALEZAS as $clave => $texto)
                        <option value="{{ $clave }}" @selected($v('naturaleza', 'operativo') === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="ambito" class="{{ $etiqueta }}">Ámbito</label>
                <select id="ambito" name="ambito" x-model="ambito" class="{{ $control }}">
                    @foreach (Gasto::AMBITOS as $clave => $texto)
                        @if ($clave === 'empresarial' || auth()->user()->can('gastos.personales'))
                            <option value="{{ $clave }}" @selected($v('ambito', 'empresarial') === $clave)>{{ $texto }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div x-show="ambito === 'personal'" x-cloak>
                <label for="persona" class="{{ $etiqueta }}">Persona</label>
                <input id="persona" name="persona" type="text" maxlength="180"
                       value="{{ $v('persona') }}" class="{{ $control }}">
            </div>

            <div>
                <label for="moneda" class="{{ $etiqueta }}">Moneda</label>
                <select id="moneda" name="moneda" class="{{ $control }}">
                    @foreach (config('gastos.monedas') as $moneda)
                        <option value="{{ $moneda }}" @selected($v('moneda') === $moneda)>{{ $moneda }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="responsable_id" class="{{ $etiqueta }}">Responsable</label>
                <select id="responsable_id" name="responsable_id" class="{{ $control }}">
                    @foreach ($usuarios as $u)
                        <option value="{{ $u->id }}" @selected((int) $v('responsable_id', auth()->id()) === $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="documentacion" class="{{ $etiqueta }}">Documentación esperada</label>
                <select id="documentacion" name="documentacion" class="{{ $control }}">
                    @foreach (Gasto::DOCUMENTACION as $clave => $texto)
                        <option value="{{ $clave }}" @selected($v('documentacion', 'pendiente') === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label for="observaciones" class="{{ $etiqueta }}">Observaciones</label>
                <textarea id="observaciones" name="observaciones" rows="2" maxlength="2000" class="{{ str_replace('min-h-11', '', $control) }}">{{ $v('observaciones') }}</textarea>
            </div>
        </div>
    </section>

    <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cuánto</h2>

        <fieldset>
            <legend class="sr-only">Tipo de monto</legend>
            <div class="space-y-2">
                @foreach (Regla::MONTO_MODOS as $clave => $texto)
                    <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700">
                        <input type="radio" name="monto_modo" value="{{ $clave }}" x-model="modo"
                               @checked($v('monto_modo', 'fijo') === $clave)
                               class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>{{ $texto }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div x-show="modo === 'fijo'" x-cloak>
            <label for="importe" class="{{ $etiqueta }}">Importe de cada período</label>
            <input id="importe" name="importe" type="text" inputmode="decimal"
                   value="{{ $v('importe') }}" class="{{ $control }} sm:max-w-xs">
        </div>

        {{-- Monto variable: se dice explícitamente que NO se inventa una cifra. Es la
             diferencia entre recordar un trámite y crear una deuda que nadie calculó. --}}
        <p x-show="modo === 'variable'" x-cloak class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Cada período va a crear una obligación <strong>sin importe</strong>, en la pestaña «Por completar».
            No suma a pendiente ni a vencido, y no se puede pagar hasta que alguien ponga la cifra del recibo.
            El sistema no estima montos.
        </p>
    </section>

    <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cuándo</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="frecuencia" class="{{ $etiqueta }}">Frecuencia</label>
                <select id="frecuencia" name="frecuencia" x-model="frecuencia" class="{{ $control }}">
                    @foreach (CalendarioRecurrencia::FRECUENCIAS as $clave => $texto)
                        <option value="{{ $clave }}" @selected($v('frecuencia', 'mensual') === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="frecuencia === 'semanal'" x-cloak>
                <label for="dia_semana" class="{{ $etiqueta }}">Día de la semana</label>
                <select id="dia_semana" name="dia_semana" class="{{ $control }}">
                    @foreach (CalendarioRecurrencia::DIAS_SEMANA as $n => $texto)
                        <option value="{{ $n }}" @selected((int) $v('dia_semana', 1) === $n)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="frecuencia === 'anual'" x-cloak>
                <label for="mes" class="{{ $etiqueta }}">Mes</label>
                <select id="mes" name="mes" class="{{ $control }}">
                    @foreach (['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'] as $i => $nombreMes)
                        <option value="{{ $i + 1 }}" @selected((int) $v('mes', 1) === $i + 1)>{{ ucfirst($nombreMes) }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Selector y no casilla numérica: «último día del mes» tiene que poder
                 elegirse por su nombre. Escribir 31 hace lo mismo, pero obliga al
                 operador a deducir que en febrero eso significa el 28. --}}
            <div x-show="frecuencia !== 'semanal'" x-cloak>
                <label for="dia_mes" class="{{ $etiqueta }}">
                    <span x-show="frecuencia === 'quincenal'">Primera fecha del mes</span>
                    <span x-show="frecuencia !== 'quincenal'">Día del mes</span>
                </label>
                <select id="dia_mes" name="dia_mes" class="{{ $control }} sm:max-w-[14rem]">
                    @foreach (CalendarioRecurrencia::diasDelMes() as $n => $texto)
                        <option value="{{ $n }}" @selected((int) $v('dia_mes', 1) === $n)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="frecuencia === 'quincenal'" x-cloak>
                <label for="dia_mes_2" class="{{ $etiqueta }}">Segunda fecha del mes</label>
                <select id="dia_mes_2" name="dia_mes_2" class="{{ $control }} sm:max-w-[14rem]">
                    @foreach (CalendarioRecurrencia::diasDelMes() as $n => $texto)
                        <option value="{{ $n }}" @selected((int) $v('dia_mes_2', CalendarioRecurrencia::ULTIMO_DIA) === $n)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            {{-- La confusión que hay que matar de entrada: quincenal NO es cada 14 días.
                 Solo se muestra cuando esa frecuencia está elegida, para no cargar la
                 pantalla de advertencias que nadie pidió. --}}
            <p x-show="frecuencia === 'quincenal'" x-cloak class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 sm:col-span-2">
                {{ CalendarioRecurrencia::POLITICA_QUINCENAL }}
            </p>

            <div>
                <label for="dias_generar_antes" class="{{ $etiqueta }}">Crear la obligación con anticipación</label>
                <input id="dias_generar_antes" name="dias_generar_antes" type="number" min="0" max="60"
                       value="{{ $v('dias_generar_antes', 0) }}" class="{{ $control }} sm:max-w-[8rem]">
                <p class="mt-1 text-xs text-gray-500">Días antes del vencimiento. Adelanta la creación, no el vencimiento.</p>
            </div>

            <div>
                <label for="vigente_desde" class="{{ $etiqueta }}">Desde</label>
                <input id="vigente_desde" name="vigente_desde" type="date" required
                       value="{{ $v('vigente_desde', $hoy ?? null) instanceof \DateTimeInterface ? $v('vigente_desde')->format('Y-m-d') : $v('vigente_desde', $hoy ?? null) }}"
                       class="{{ $control }}">
            </div>

            <div>
                <label for="vigente_hasta" class="{{ $etiqueta }}">Hasta (opcional)</label>
                <input id="vigente_hasta" name="vigente_hasta" type="date"
                       value="{{ $v('vigente_hasta') instanceof \DateTimeInterface ? $v('vigente_hasta')->format('Y-m-d') : $v('vigente_hasta') }}"
                       class="{{ $control }}">
                <p class="mt-1 text-xs text-gray-500">Sin fecha, sigue hasta que la pauses o la canceles.</p>
            </div>
        </div>

        {{-- La política de días 29-31 se MUESTRA al configurar, no se descubre el 31 de
             abril. Queda además resuelta y guardada en la fecha de cada ocurrencia. --}}
        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
            {{ CalendarioRecurrencia::POLITICA_DIAS }}
        </p>
    </section>

    <datalist id="categorias-regla">
        @foreach (\App\Models\Gastos\Gasto::query()->select('categoria')->distinct()->pluck('categoria') as $c)
            <option value="{{ $c }}"></option>
        @endforeach
    </datalist>
</div>
