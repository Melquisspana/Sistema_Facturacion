@php
    use App\Services\Gastos\Dinero;

    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

    $inicial = [
        'cuotas' => $cuotas->map(fn ($c) => [
            'id' => (int) $c->cuota_id,
            'gastoId' => (int) $c->gasto_id,
            'concepto' => $c->concepto,
            'numero' => (int) $c->numero,
            'vence' => $c->vence,
            'vencida' => (bool) $c->vencida,
            'ambito' => $c->ambito,
            'saldo' => Dinero::mostrar((int) $c->saldo),
            'aplicar' => (string) old('aplicar.'.$c->cuota_id, ''),
        ])->values()->all(),
        'importe' => (string) old('importe', ''),
        'moneda' => $moneda,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Registrar pago</h1>
        <p class="text-sm text-gray-500">Un pago puede cubrir varias obligaciones del mismo destinatario y moneda.</p>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-4">
            <x-gastos-aviso />

            {{-- Elegir destinatario recarga la lista de cuotas. Es un GET a propósito:
                 así la pantalla es enlazable y el botón «atrás» funciona. --}}
            <form method="GET" action="{{ route('gastos.pagos.create') }}" class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="beneficiario" class="block text-sm font-medium text-gray-700">¿A quién le pagás? *</label>
                        <select id="beneficiario" name="beneficiario" class="{{ $control }}" onchange="this.form.submit()">
                            <option value="">Elegí un destinatario con saldo…</option>
                            @foreach ($beneficiarios as $b)
                                <option value="{{ $b->beneficiario }}" @selected($beneficiario === $b->beneficiario && $moneda === $b->moneda)>
                                    {{ $b->beneficiario }} — {{ $b->moneda }} {{ Dinero::mostrar((int) $b->pendiente) }} en {{ $b->gastos }} obligación(es)
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Sin catálogo de proveedores todavía, el destinatario se agrupa por nombre exacto: no se fusiona nada por parecido.</p>
                    </div>
                    <div>
                        <label for="moneda" class="block text-sm font-medium text-gray-700">Moneda *</label>
                        <select id="moneda" name="moneda" class="{{ $control }}" onchange="this.form.submit()">
                            @foreach (config('gastos.monedas') as $m)<option @selected($moneda === $m)>{{ $m }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <noscript><button type="submit" class="mt-2 min-h-11 rounded-md border border-gray-300 px-4 text-sm">Cargar obligaciones</button></noscript>
            </form>

            @if ($beneficiario === '')
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                    Elegí un destinatario para ver sus obligaciones abiertas.
                </div>
            @elseif ($cuotas->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                    {{ $beneficiario }} no tiene saldo abierto en {{ $moneda }}.
                </div>
            @else
                <form method="POST" action="{{ route('gastos.pagos.store') }}" enctype="multipart/form-data"
                      x-data="pagoFormulario(@js($inicial))" @submit="enviar($event)"
                      class="space-y-4 rounded-lg bg-white p-4 shadow sm:p-5">
                    @csrf
                    <input type="hidden" name="clave" value="{{ old('clave', $clave) }}">
                    @if ($gastoOrigen)<input type="hidden" name="volver_a" value="{{ $gastoOrigen->id }}">@endif

                    <section>
                        <h2 class="text-base font-semibold text-gray-800">Datos del pago</h2>
                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="importe" class="block text-sm font-medium text-gray-700">¿Cuánto pagaste? *</label>
                                <input id="importe" name="importe" x-model="importe" inputmode="decimal" required class="{{ $control }} text-lg tabular-nums" placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Puede ser el total o un abono.</p>
                            </div>
                            <div>
                                <label for="fecha" class="block text-sm font-medium text-gray-700">Fecha real del pago *</label>
                                <input id="fecha" name="fecha" type="date" value="{{ old('fecha', $hoy) }}" max="{{ $hoy }}" required class="{{ $control }} min-w-0">
                            </div>
                            <div>
                                <label for="metodo" class="block text-sm font-medium text-gray-700">¿Cómo pagaste? *</label>
                                <select id="metodo" name="metodo" class="{{ $control }}">
                                    @foreach (config('gastos.metodos') as $valor => $texto)<option value="{{ $valor }}" @selected(old('metodo', 'transferencia') === $valor)>{{ $texto }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="pagado_por" class="block text-sm font-medium text-gray-700">¿Quién pagó? *</label>
                                <select id="pagado_por" name="pagado_por" class="{{ $control }}">
                                    @foreach ($usuarios as $u)<option value="{{ $u->id }}" @selected((int) old('pagado_por', auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="referencia" class="block text-sm font-medium text-gray-700">Referencia (opcional)</label>
                                <input id="referencia" name="referencia" value="{{ old('referencia') }}" maxlength="180" class="{{ $control }}" placeholder="Número de transferencia o recibo">
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-gray-800">Reparto entre obligaciones</h2>
                            <button type="button" @click="repartir()" class="min-h-11 text-sm text-indigo-600 underline">Cubrir primero las más antiguas</button>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">El reparto es explícito: se guarda lo que dejes acá, y el servidor lo verifica contra el saldo real de cada cuota.</p>

                        <ul class="mt-2 divide-y divide-gray-100 rounded-md border border-gray-200">
                            <template x-for="c in cuotas" :key="c.id">
                                <li class="grid grid-cols-1 gap-x-3 gap-y-1 p-3 sm:grid-cols-12 sm:items-center">
                                    <div class="sm:col-span-7">
                                        <p class="text-sm text-gray-800">
                                            <span x-text="c.concepto"></span>
                                            <span class="text-gray-500">· cuota <span x-text="c.numero"></span></span>
                                        </p>
                                        <p class="text-xs">
                                            <span :class="c.vencida ? 'text-red-700 font-medium' : 'text-gray-500'"
                                                  x-text="(c.vence || 'Sin fecha') + (c.vencida ? ' · vencida' : '')"></span>
                                            <span class="text-gray-500"> · saldo <span x-text="moneda + ' ' + c.saldo_txt"></span></span>
                                            <template x-if="c.ambito === 'personal'">
                                                <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-violet-700">Personal</span>
                                            </template>
                                        </p>
                                    </div>
                                    <div class="sm:col-span-5">
                                        <label class="sr-only" :for="'aplicar-' + c.id">Aplicar a <span x-text="c.concepto"></span></label>
                                        <input :id="'aplicar-' + c.id" :name="'aplicar[' + c.id + ']'" x-model="c.aplicar"
                                               inputmode="decimal" class="{{ $control }} tabular-nums" placeholder="0.00">
                                        <p x-show="excede(c)" x-cloak class="mt-1 text-xs text-red-600">Supera el saldo de esta cuota.</p>
                                    </div>
                                </li>
                            </template>
                        </ul>

                        <p class="mt-2 text-sm" :class="cuadra ? 'text-gray-700' : 'text-red-600'">
                            Repartido: <strong x-text="moneda + ' ' + formato(totalAplicado)"></strong>
                            · Importe del pago: <strong x-text="moneda + ' ' + formato(centavosDe(importe))"></strong>
                            <span x-show="!cuadra" x-cloak> · deben coincidir exactamente.</span>
                        </p>
                    </section>

                    <section class="border-t border-gray-200 pt-4">
                        <h2 class="text-base font-semibold text-gray-800">Comprobantes</h2>
                        <div class="mt-2"><x-gastos-adjuntos nombre="comprobantes" titulo="Comprobantes del pago" /></div>
                        <div class="mt-2">
                            <label for="sin_comprobante" class="block text-sm font-medium text-gray-700">Si no adjuntás comprobante, indicá el motivo</label>
                            <input id="sin_comprobante" name="sin_comprobante" value="{{ old('sin_comprobante') }}" maxlength="250" class="{{ $control }}" placeholder="Ej. Pago en efectivo sin recibo; lo adjuntaré después">
                            <p class="mt-1 text-xs text-gray-500">Podés adjuntarlo después desde la ficha del pago; el motivo queda en el historial.</p>
                        </div>
                    </section>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                        <p class="text-xs text-gray-500">Quedará registrado por {{ auth()->user()->name }}. El sistema no ejecuta la transferencia.</p>
                        <button type="submit" :disabled="enviando" class="min-h-12 w-full rounded-md bg-indigo-600 px-6 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:w-auto"
                                x-text="enviando ? 'Registrando…' : 'Registrar pago'">Registrar pago</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
