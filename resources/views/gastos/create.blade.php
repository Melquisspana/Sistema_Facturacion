@php
    use App\Models\Gastos\Gasto;

    // Estado inicial de Alpine. Al volver de un error se rehidrata desde old(); los
    // archivos NO se pueden rehidratar y por eso el aviso pide volver a elegirlos.
    // Las cuotas se normalizan campo por campo: old() devuelve lo que MANDÓ el
    // cliente, así que no se asume ni que sea un arreglo ni que traiga las claves.
    $cuotasPrevias = collect(is_array(old('cuotas')) ? old('cuotas') : [])
        ->filter(fn ($fila) => is_array($fila))
        ->map(fn ($fila) => [
            'importe' => is_scalar($fila['importe'] ?? null) ? (string) $fila['importe'] : '',
            'vence' => is_scalar($fila['vence'] ?? null) ? (string) $fila['vence'] : '',
            'aplicar' => is_scalar($fila['aplicar'] ?? null) ? (string) $fila['aplicar'] : '',
        ])
        ->take(config('gastos.max_cuotas'))->values()->all();

    $texto = fn (string $campo, string $porDefecto = '') => is_scalar(old($campo)) ? (string) old($campo) : $porDefecto;

    // Prellenado desde Compras: solo se usa la PRIMERA vez. Si el envío falló, mandan
    // los valores que el usuario ya había tecleado.
    $pre = $prellenado ?? [];
    $desdeCompras = $documento !== null && $errors->isEmpty();

    $inicial = [
        'ambito' => in_array(old('ambito'), ['empresarial', 'personal'], true) ? old('ambito') : 'empresarial',
        'importe' => $texto('importe', $desdeCompras ? (string) ($pre['importe'] ?? '') : ''),
        'montoPendiente' => (bool) old('monto_pendiente', false),
        'pagado' => (bool) old('ya_pagado', false),
        'pagoImporte' => $texto('pago_importe'),
        'cuotas' => $cuotasPrevias ?: [[
            'importe' => $desdeCompras ? (string) ($pre['importe'] ?? '') : '',
            'vence' => $desdeCompras ? (string) ($pre['vence'] ?? '') : '',
            'aplicar' => '',
        ]],
        'maxCuotas' => (int) config('gastos.max_cuotas'),
    ];

    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $etiqueta = 'block text-sm font-medium text-gray-700';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Registrar gasto</h1>
            <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al panel</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl">
            @if ($confirmacion)
                @include('gastos.partials.confirmacion', $confirmacion)
            @endif

            @if ($documento)
                <div class="mb-3 rounded-md border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-800">
                    <p class="font-medium">Datos tomados del documento de Compras {{ $documento->numero_control ?: $documento->codigo_generacion }}.</p>
                    @if ($gastoDelDocumento)
                        <p class="mt-0.5">Ojo: este documento <a href="{{ route('gastos.show', $gastoDelDocumento) }}" class="underline">ya originó un gasto</a>. Guardar acá crearía una segunda deuda por el mismo papel.</p>
                    @elseif ($documentoBloqueado)
                        <p class="mt-0.5">{{ $documentoBloqueado }}</p>
                    @else
                        <p class="mt-0.5">Revisá categoría, ámbito, vencimiento y responsable: eso no viaja en el documento.</p>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('gastos.store') }}" enctype="multipart/form-data"
                  x-data="gastoFormulario(@js($inicial))" @submit="enviar($event)"
                  class="rounded-lg bg-white p-4 shadow sm:p-5">
                @csrf
                <input type="hidden" name="clave" value="{{ old('clave', $clave) }}">
                {{-- Devuelve el documento del que salieron estos datos. Sin esto el
                     formulario prellenaba desde Compras y después se olvidaba de dónde
                     venía, así que nunca se guardaba el vínculo que impide que el mismo
                     papel origine una segunda deuda. --}}
                @if ($documento)
                    <input type="hidden" name="documento" value="{{ $documento->id }}">
                @endif
                {{-- value= además de :value= para que el campo tenga un valor válido
                     aunque Alpine no haya arrancado todavía. --}}
                <input type="hidden" name="ya_pagado" value="{{ $inicial['pagado'] ? 1 : 0 }}" :value="pagado ? 1 : 0">
                <input type="hidden" name="monto_pendiente" value="{{ $inicial['montoPendiente'] ? 1 : 0 }}" :value="montoPendiente ? 1 : 0">

                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert" tabindex="-1" x-init="$el.focus()">
                        <p class="font-medium">Revisá estos datos. No se guardó un registro nuevo.</p>
                        <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        <p class="mt-1">Si adjuntaste archivos, volvé a seleccionarlos.</p>
                    </div>
                @endif
                <noscript><p class="mb-3 text-sm text-red-700">Activá JavaScript para repartir cuotas y adjuntar comprobantes.</p></noscript>

                <fieldset class="mb-5 border-b border-gray-200 pb-4">
                    <legend class="text-sm font-semibold text-gray-800">Pago del gasto</legend>
                    <div class="mt-1 flex flex-wrap gap-x-6">
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-gray-700">
                            <input type="radio" name="situacion_visual" value="pendiente" :checked="!pagado" @change="pagado = false" class="h-5 w-5 text-indigo-600 focus:ring-indigo-500">
                            Pendiente de pago
                        </label>
                        @can('gastos.pagos.registrar')
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-gray-800">
                                <input type="radio" name="situacion_visual" value="pagado" :checked="pagado" @change="pagado = true; cambiarPago()" class="h-5 w-5 text-indigo-600 focus:ring-indigo-500">
                                Ya lo pagué
                            </label>
                        @endcan
                    </div>
                </fieldset>

                <section aria-labelledby="datos-gasto">
                    <h2 id="datos-gasto" class="text-base font-semibold text-gray-800">Datos del gasto</h2>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="concepto" class="{{ $etiqueta }}">Concepto *</label>
                            <input id="concepto" name="concepto" value="{{ $texto('concepto', $desdeCompras ? (string) ($pre['concepto'] ?? '') : '') }}" maxlength="200" required class="{{ $control }}" placeholder="Ej. Internet de septiembre, insumos, universidad">
                            <x-input-error :messages="$errors->get('concepto')" />
                        </div>
                        <div>
                            <label for="beneficiario" class="{{ $etiqueta }}">¿A quién se paga? *</label>
                            <input id="beneficiario" name="beneficiario" value="{{ $texto('beneficiario', $desdeCompras ? (string) ($pre['beneficiario'] ?? '') : '') }}" maxlength="180" required class="{{ $control }}" placeholder="Proveedor, institución o persona">
                            <x-input-error :messages="$errors->get('beneficiario')" />
                        </div>
                        <div>
                            <label for="categoria" class="{{ $etiqueta }}">Categoría *</label>
                            <input id="categoria" name="categoria" value="{{ $texto('categoria') }}" maxlength="100" required class="{{ $control }}" placeholder="Ej. Servicios, insumos, educación">
                            <x-input-error :messages="$errors->get('categoria')" />
                        </div>
                        <div>
                            <label for="ambito" class="{{ $etiqueta }}">Este gasto es *</label>
                            <select id="ambito" name="ambito" x-model="ambito" class="{{ $control }}">
                                <option value="empresarial">De la empresa</option>
                                @can('gastos.personales')<option value="personal">Personal</option>@endcan
                            </select>
                        </div>
                        <div>
                            <label for="responsable_id" class="{{ $etiqueta }}">Responsable *</label>
                            <select id="responsable_id" name="responsable_id" required class="{{ $control }}">
                                @foreach ($usuarios as $usuario)<option value="{{ $usuario->id }}" @selected(old('responsable_id', auth()->id()) == $usuario->id)>{{ $usuario->name }}</option>@endforeach
                            </select>
                        </div>
                        <div x-show="ambito === 'personal'" x-cloak class="sm:col-span-2">
                            <label for="persona" class="{{ $etiqueta }}">¿Para quién es? (opcional)</label>
                            <input id="persona" name="persona" value="{{ $texto('persona') }}" maxlength="180" :disabled="ambito !== 'personal'" class="{{ $control }}" placeholder="Nombre de la persona; por ejemplo, el estudiante">
                        </div>
                    </div>
                </section>

                <section aria-labelledby="importe-fechas" class="mt-5 border-t border-gray-200 pt-4">
                    <h2 id="importe-fechas" class="text-base font-semibold text-gray-800">Importe y vencimiento</h2>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label for="importe" class="{{ $etiqueta }}">Importe total *</label>
                            <input id="importe" name="importe" x-model="importe" @input="sincronizar()" :disabled="montoPendiente" :required="!montoPendiente" inputmode="decimal" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" class="{{ $control }} text-lg tabular-nums" placeholder="0.00">
                            <x-input-error :messages="$errors->get('importe')" />
                        </div>
                        <div>
                            <label for="moneda" class="{{ $etiqueta }}">Moneda *</label>
                            <select id="moneda" name="moneda" class="{{ $control }}">@foreach (config('gastos.monedas') as $moneda)<option @selected(old('moneda', 'USD') === $moneda)>{{ $moneda }}</option>@endforeach</select>
                        </div>
                    </div>

                    <label class="mt-2 flex min-h-11 items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" x-model="montoPendiente" :disabled="pagado" class="h-5 w-5 rounded text-indigo-600">
                        Todavía no conozco el monto
                    </label>
                    <p x-show="montoPendiente" x-cloak class="text-sm text-gray-500">No contará como deuda ni como vencido hasta que lo completes.</p>

                    <fieldset :disabled="montoPendiente" x-show="!montoPendiente" class="mt-2 space-y-2">
                        <template x-for="(cuota, i) in cuotas" :key="i">
                            <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-12"
                                 :class="cuotas.length > 1 && 'rounded-md border border-gray-200 p-2.5'">
                                <div :class="cuotas.length > 1 ? 'sm:col-span-5' : 'sm:col-span-12'">
                                    <label :for="'vence-' + i" class="{{ $etiqueta }}" x-text="cuotas.length > 1 ? 'Vence la cuota ' + (i + 1) : 'Fecha de vencimiento (opcional)'"></label>
                                    <input type="date" :id="'vence-' + i" :name="'cuotas[' + i + '][vence]'" x-model="cuota.vence" class="{{ $control }} min-w-0">
                                    {{-- Viniendo de Compras, el vencimiento llega VACÍO a propósito: el
                                         documento trae su fecha de emisión, que no es la fecha límite de
                                         pago. Se muestra como dato, no como propuesta. --}}
                                    @if ($desdeCompras && filled($pre['fecha_documento'] ?? null))
                                        <p x-show="i === 0" class="mt-1 text-xs text-gray-500">
                                            El documento se emitió el
                                            {{ \Carbon\CarbonImmutable::parse($pre['fecha_documento'])->format('d/m/Y') }};
                                            esa no es su fecha de pago. Escribí la del papel, o dejalo sin vencimiento.
                                        </p>
                                    @endif
                                </div>
                                <div x-show="cuotas.length > 1" class="sm:col-span-5">
                                    <label :for="'cuota-' + i" class="{{ $etiqueta }}">Importe de la cuota *</label>
                                    {{-- `required` SOLO cuando el campo se ve: con una cuota el input
                                         sigue en el DOM (oculto) y lo rellena sincronizar(). Marcarlo
                                         obligatorio haría que el navegador intentara enfocar un campo
                                         invisible y bloqueara el envío sin decir por qué. --}}
                                    <input :id="'cuota-' + i" :name="'cuotas[' + i + '][importe]'" x-model="cuota.importe" inputmode="decimal" :required="!montoPendiente && cuotas.length > 1" class="{{ $control }} tabular-nums">
                                </div>
                                <button x-show="cuotas.length > 1" type="button" @click="quitarCuota(i)" class="min-h-11 text-sm text-red-600 sm:col-span-2" :aria-label="'Quitar cuota ' + (i + 1)">Quitar</button>
                            </div>
                        </template>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-gray-500">Sin fecha no se marca como vencido.</p>
                            <button type="button" @click="agregarCuota()" :disabled="cuotas.length >= maxCuotas" class="min-h-11 text-sm font-medium text-indigo-600">+ Agregar cuota</button>
                        </div>
                        <p x-show="cuotas.length > 1" class="text-sm text-gray-700">Total de cuotas: <strong x-text="formato(totalCuotas)"></strong>
                            <span x-show="diferenciaCuotas !== '0.00'" class="text-red-600">· diferencia <span x-text="diferenciaCuotas"></span>.</span>
                        </p>
                        <x-input-error :messages="$errors->get('cuotas')" />
                    </fieldset>
                </section>

                <section aria-labelledby="documentos-gasto" class="mt-5 border-t border-gray-200 pt-4">
                    <h2 id="documentos-gasto" class="text-base font-semibold text-gray-800">Documento del gasto</h2>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label for="documentacion" class="{{ $etiqueta }}">¿Te entregaron documento?</label>
                            <select id="documentacion" name="documentacion" class="{{ $control }}">
                                @foreach (['pendiente' => 'Lo adjuntaré después', 'adjunto' => 'Lo adjunto ahora', 'no_entregaron' => 'No entregaron documento'] as $valor => $texto2)
                                    <option value="{{ $valor }}" @selected(old('documentacion', $desdeCompras ? ($pre['documentacion'] ?? 'pendiente') : 'pendiente') === $valor)>{{ $texto2 }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2"><x-gastos-adjuntos nombre="documentos" titulo="Documentos del gasto (opcional)" /></div>
                    </div>
                </section>

                <fieldset x-show="pagado" x-cloak :disabled="!pagado" aria-labelledby="datos-pago" class="mt-5 border-t border-gray-200 pt-4">
                    <h2 id="datos-pago" class="text-base font-semibold text-gray-800">Pago realizado</h2>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="pago_importe" class="{{ $etiqueta }}">¿Cuánto pagaste? *</label>
                            <input id="pago_importe" name="pago_importe" x-model="pagoImporte" @input="sincronizar()" :required="pagado" inputmode="decimal" class="{{ $control }} text-lg tabular-nums" placeholder="0.00">
                            <p class="mt-1 text-xs text-gray-500">El total o un abono.</p>
                            <x-input-error :messages="$errors->get('pago_importe')" />
                        </div>
                        <div>
                            <label for="pago_fecha" class="{{ $etiqueta }}">Fecha real del pago *</label>
                            <input id="pago_fecha" name="pago_fecha" type="date" value="{{ old('pago_fecha', now()->toDateString()) }}" max="{{ now()->toDateString() }}" :required="pagado" class="{{ $control }} min-w-0">
                        </div>
                        <div>
                            <label for="pago_metodo" class="{{ $etiqueta }}">¿Cómo pagaste? *</label>
                            <select id="pago_metodo" name="pago_metodo" class="{{ $control }}">
                                @foreach (config('gastos.metodos') as $valor => $texto2)<option value="{{ $valor }}" @selected(old('pago_metodo', 'transferencia') === $valor)>{{ $texto2 }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="pagado_por" class="{{ $etiqueta }}">¿Quién pagó? *</label>
                            <select id="pagado_por" name="pagado_por" class="{{ $control }}">
                                @foreach ($usuarios as $usuario)<option value="{{ $usuario->id }}" @selected(old('pagado_por', auth()->id()) == $usuario->id)>{{ $usuario->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="pago_referencia" class="{{ $etiqueta }}">Referencia (opcional)</label>
                            <input id="pago_referencia" name="pago_referencia" value="{{ $texto('pago_referencia') }}" maxlength="180" class="{{ $control }}" placeholder="Número de transferencia o recibo">
                        </div>
                    </div>

                    <div x-show="cuotas.length > 1" class="mt-3 space-y-2 rounded-md border border-gray-200 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-800">Reparto entre cuotas</h3>
                            <button type="button" @click="repartir()" class="min-h-11 text-sm text-indigo-600 underline">Cubrir primero las más antiguas</button>
                        </div>
                        <template x-for="(cuota, i) in cuotas" :key="i">
                            <div class="grid grid-cols-1 items-center gap-x-3 gap-y-1 sm:grid-cols-2">
                                <label :for="'aplicar-' + i" class="text-sm text-gray-700">Cuota <span x-text="i + 1"></span> · <span x-text="cuota.vence || 'Sin fecha'"></span></label>
                                <input :id="'aplicar-' + i" :name="'cuotas[' + i + '][aplicar]'" x-model="cuota.aplicar" inputmode="decimal" class="{{ $control }} tabular-nums" placeholder="0.00">
                            </div>
                        </template>
                        <p class="text-sm text-gray-700">Repartido: <strong x-text="formato(totalAplicado)"></strong></p>
                    </div>

                    <p class="mt-2 text-sm text-gray-700" aria-live="polite">Quedará pendiente: <strong x-text="restantePago" class="tabular-nums"></strong>.</p>

                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <x-gastos-adjuntos nombre="comprobantes" titulo="Comprobantes del pago" />
                        <div>
                            <label for="sin_comprobante" class="{{ $etiqueta }}">Si no adjuntás comprobante, indicá el motivo</label>
                            <input id="sin_comprobante" name="sin_comprobante" value="{{ $texto('sin_comprobante') }}" maxlength="250" class="{{ $control }}" placeholder="Ej. Efectivo sin recibo; lo adjuntaré después">
                            <p class="mt-1 text-xs text-gray-500">Podés adjuntarlo después desde la ficha del pago.</p>
                            <x-input-error :messages="$errors->get('sin_comprobante')" />
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Quedará registrado por {{ auth()->user()->name }}. El sistema no realiza la transferencia.</p>
                </fieldset>

                <details class="mt-5 border-t border-gray-200 pt-3" @if($errors->has('periodo_desde') || $errors->has('periodo_hasta')) open @endif>
                    <summary class="min-h-11 cursor-pointer py-2 text-sm font-medium text-gray-700">Opciones adicionales · período, clasificación y notas</summary>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label for="periodo_desde" class="{{ $etiqueta }}">Período desde</label><input id="periodo_desde" name="periodo_desde" type="date" value="{{ $texto('periodo_desde') }}" class="{{ $control }} min-w-0"></div>
                        <div><label for="periodo_hasta" class="{{ $etiqueta }}">Período hasta</label><input id="periodo_hasta" name="periodo_hasta" type="date" value="{{ $texto('periodo_hasta') }}" class="{{ $control }} min-w-0"></div>
                        <div class="sm:col-span-2">
                            <label for="naturaleza" class="{{ $etiqueta }}">Tipo de gasto</label>
                            <select id="naturaleza" name="naturaleza" class="{{ $control }}">
                                @foreach (Gasto::NATURALEZAS as $valor => $texto2)
                                    <option value="{{ $valor }}" @selected(old('naturaleza', $desdeCompras ? ($pre['naturaleza'] ?? 'operativo') : 'operativo') === $valor)>{{ $texto2 }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2"><label for="observaciones" class="{{ $etiqueta }}">Observaciones</label><textarea id="observaciones" name="observaciones" rows="2" maxlength="2000" class="{{ $control }}">{{ $texto('observaciones') }}</textarea></div>
                    </div>
                </details>

                {{-- «Este gasto se repite». Una casilla y, si se marca, los campos de
                     cuándo. Sin asistente y sin pasos: el formulario sigue siendo uno.

                     No se vuelve a pedir NADA del gasto —ni el destinatario, ni el
                     concepto, ni el importe—: la repetición los toma de lo que ya se
                     escribió arriba. Y el gasto que se está registrando queda como el
                     primer período, así que no nace un gasto gemelo. --}}
                @if ($puedeRepetir)
                    <section aria-labelledby="se-repite" class="mt-5 border-t border-gray-200 pt-4"
                             x-data="{ repite: {{ old('se_repite') ? 'true' : 'false' }} }">
                        <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-gray-800">
                            <input type="checkbox" name="se_repite" value="1" x-model="repite" @checked(old('se_repite'))
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span id="se-repite">Este gasto se repite</span>
                        </label>

                        <div x-show="repite" x-cloak class="mt-3 space-y-3">
                            <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                Este que estás registrando queda como el <strong>primero</strong>. Los siguientes se van
                                creando solos cuando toque, y <strong>nacen sin pagar</strong> — aunque este lo marques como pagado.
                            </p>
                            @include('gastos.partials.repeticion', ['prefijo' => 'repeticion'])
                        </div>
                    </section>
                @endif

                <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                    <p class="text-xs text-gray-500">* Campos obligatorios</p>
                    <button type="submit" :disabled="enviando" class="min-h-12 w-full rounded-md bg-indigo-600 px-6 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 sm:w-auto"
                            x-text="enviando ? 'Guardando…' : (pagado ? 'Guardar gasto y pago' : 'Guardar gasto')">Guardar gasto</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
