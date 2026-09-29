@php
    use App\Models\Gastos\Ajuste;
    use App\Services\Gastos\Dinero;
    use App\Services\Gastos\SaldosGastos;

    $control = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $puedeAjustar = auth()->user()->can('gastos.administrar');
    $puedePagar = auth()->user()->can('gastos.pagos.registrar');
    $puedeCorregir = auth()->user()->can('gastos.pagos.corregir');

    $acciones = [
        'gasto_registrado' => 'Gasto registrado',
        'pago_registrado' => 'Pago registrado',
        'pago_revertido' => 'Pago revertido',
        'ajuste_registrado' => 'Ajuste registrado',
        'ajuste_revertido' => 'Ajuste revertido',
        'documentos_adjuntados' => 'Documentos adjuntados',
        'comprobantes_adjuntados' => 'Comprobantes adjuntados',
        'compra_origino_gasto' => 'Originado desde Compras',
        'compra_vinculada_como_respaldo' => 'Documento de Compras vinculado como respaldo',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold leading-tight text-gray-800">{{ $gasto->concepto }}</h1>
                <p class="text-sm text-gray-500">{{ $gasto->beneficiario }} · Gasto #{{ $gasto->id }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver</a>
                @if ($puedePagar && $resumen['pendiente'] !== null && $resumen['pendiente'] > 0)
                    <a href="{{ route('gastos.pagos.create', ['gasto' => $gasto->id]) }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Registrar pago</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-4">
            <x-gastos-aviso />

            @if ($gasto->montoDesconocido())
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-900">Esperando el monto</p>
                    <p class="mt-0.5 text-sm text-amber-800">
                        No suma a pendiente ni a vencido, y no se puede pagar hasta completarla.
                    </p>
                    @can('gastos.registrar')
                        {{-- Completa ESTE registro; no crea otro. Registrar uno nuevo dejaría
                             dos filas por la misma deuda y habría que acordarse de cancelar la
                             primera, que es justo el error que este módulo evita. --}}
                        <form method="POST" action="{{ route('gastos.completar', $gasto) }}"
                              x-data="{ cuotas: [{ importe: '', vence: '' }] }"
                              class="mt-3 space-y-3 rounded-md border border-amber-200 bg-white p-3">
                            @csrf
                            <p class="text-xs text-gray-500">Se completa esta misma obligación: no se crea un registro nuevo.</p>
                            <template x-for="(c, i) in cuotas" :key="i">
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-12 sm:items-end">
                                    <div :class="cuotas.length > 1 ? 'sm:col-span-5' : 'sm:col-span-6'">
                                        <label :for="'comp-importe-' + i" class="block text-sm font-medium text-gray-700"
                                               x-text="cuotas.length > 1 ? 'Importe de la cuota ' + (i + 1) + ' *' : 'Importe total *'"></label>
                                        <input :id="'comp-importe-' + i" :name="'cuotas[' + i + '][importe]'" x-model="c.importe"
                                               inputmode="decimal" required class="{{ $control }} tabular-nums" placeholder="0.00">
                                    </div>
                                    <div :class="cuotas.length > 1 ? 'sm:col-span-5' : 'sm:col-span-6'">
                                        <label :for="'comp-vence-' + i" class="block text-sm font-medium text-gray-700">Vence (opcional)</label>
                                        <input type="date" :id="'comp-vence-' + i" :name="'cuotas[' + i + '][vence]'" x-model="c.vence" class="{{ $control }} min-w-0">
                                    </div>
                                    <button x-show="cuotas.length > 1" type="button" @click="cuotas.splice(i, 1)"
                                            class="min-h-11 text-sm text-red-600 sm:col-span-2" :aria-label="'Quitar cuota ' + (i + 1)">Quitar</button>
                                </div>
                            </template>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <button type="button" @click="cuotas.length < {{ config('gastos.max_cuotas') }} && cuotas.push({ importe: '', vence: '' })"
                                        class="min-h-11 text-sm font-medium text-indigo-600">+ Agregar cuota</button>
                                <button type="submit" class="min-h-11 rounded-md bg-amber-700 px-4 text-sm font-medium text-white">Completar y dejar lista para pagar</button>
                            </div>
                        </form>
                    @endcan
                </div>
            @endif

            {{-- Cabecera de saldos. Los tres ejes van visibles y separados. --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                    <div class="order-2 shrink-0 sm:order-none"><x-gastos-situacion :resumen="$resumen" /></div>
                    <dl class="order-1 grid grid-cols-2 gap-4 sm:order-none sm:flex-1 sm:grid-cols-4">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Importe</dt>
                            <dd class="mt-0.5 text-base font-semibold tabular-nums text-gray-900">{{ $gasto->montoDesconocido() ? 'Por definir' : $gasto->moneda.' '.$gasto->importe }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Pendiente</dt>
                            <dd class="mt-0.5 text-base font-semibold tabular-nums text-gray-900">{{ $resumen['pendiente'] === null ? 'Por definir' : $gasto->moneda.' '.Dinero::mostrar($resumen['pendiente']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">De ello vencido</dt>
                            <dd class="mt-0.5 text-base font-semibold tabular-nums {{ $resumen['vencido'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $gasto->moneda }} {{ Dinero::mostrar($resumen['vencido']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Pagado</dt>
                            <dd class="mt-0.5 text-base font-semibold tabular-nums text-gray-900">{{ $gasto->moneda }} {{ Dinero::mostrar($resumen['pagado']) }}</dd>
                        </div>
                    </dl>
                </div>

                @if ($resumen['credito'] > 0 || $resumen['debito'] > 0)
                    <p class="mt-3 border-t border-gray-100 pt-2 text-sm text-gray-600">
                        Ajustes vigentes:
                        @if ($resumen['credito'] > 0) crédito {{ $gasto->moneda }} {{ Dinero::mostrar($resumen['credito']) }} @endif
                        @if ($resumen['credito'] > 0 && $resumen['debito'] > 0) · @endif
                        @if ($resumen['debito'] > 0) débito {{ $gasto->moneda }} {{ Dinero::mostrar($resumen['debito']) }} @endif
                        · un ajuste no es dinero pagado.
                    </p>
                @endif

                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-gray-100 pt-3 text-sm sm:grid-cols-4">
                    <div><dt class="inline text-gray-500">Categoría:</dt> <dd class="inline text-gray-800">{{ $gasto->categoria }}</dd></div>
                    <div><dt class="inline text-gray-500">Ámbito:</dt> <dd class="inline text-gray-800">{{ $gasto->etiquetaAmbito() }}{{ $gasto->persona ? ' · '.$gasto->persona : '' }}</dd></div>
                    <div><dt class="inline text-gray-500">Tipo:</dt> <dd class="inline text-gray-800">{{ \App\Models\Gastos\Gasto::NATURALEZAS[$gasto->naturaleza] ?? $gasto->naturaleza }}</dd></div>
                    <div><dt class="inline text-gray-500">Responsable:</dt> <dd class="inline text-gray-800">{{ $gasto->responsable?->name }}</dd></div>
                    @if ($gasto->periodo_desde)
                        <div class="sm:col-span-2"><dt class="inline text-gray-500">Período:</dt> <dd class="inline text-gray-800">{{ $gasto->periodo_desde->format('d/m/Y') }} a {{ $gasto->periodo_hasta?->format('d/m/Y') }}</dd></div>
                    @endif
                    <div class="sm:col-span-2"><dt class="inline text-gray-500">Registró:</dt> <dd class="inline text-gray-800">{{ $gasto->registrador?->name }} el {{ $gasto->created_at->format('d/m/Y H:i') }}</dd></div>
                </dl>

                @if ($gasto->observaciones)
                    <p class="mt-2 whitespace-pre-line border-t border-gray-100 pt-2 text-sm text-gray-700">{{ $gasto->observaciones }}</p>
                @endif
            </div>

            {{-- Cuotas. Cada una con su saldo propio: es lo que hace que una cuota
                 futura no vuelva vencido el gasto entero. --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Cuotas y vencimientos</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="px-4 py-2 font-medium">#</th>
                                <th scope="col" class="px-4 py-2 font-medium">Vence</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Importe</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Pagado</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Ajustes</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Pendiente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($gasto->cuotas as $cuota)
                                @php
                                    $pendiente = $saldos->pendienteCuota($cuota);
                                    $credito = $saldos->ajustes($cuota->id, 'credito');
                                    $debito = $saldos->ajustes($cuota->id, 'debito');
                                    $vencida = $pendiente > 0 && $cuota->venceAntesDe($hoy);
                                @endphp
                                <tr>
                                    <th scope="row" class="px-4 py-2 text-left font-normal text-gray-700">{{ $cuota->numero }}</th>
                                    <td class="px-4 py-2 {{ $vencida ? 'font-medium text-red-700' : 'text-gray-700' }}">
                                        {{ $cuota->vence?->format('d/m/Y') ?? 'Sin fecha' }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-700">{{ Dinero::mostrar($cuota->importe) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-700">{{ Dinero::mostrar($saldos->aplicado($cuota->id)) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-600">
                                        {{ $credito || $debito ? ($debito ? '+'.Dinero::mostrar($debito).' ' : '').($credito ? '−'.Dinero::mostrar($credito) : '') : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums font-semibold text-gray-900">{{ Dinero::mostrar($pendiente) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($puedeAjustar && $gasto->cuotas->isNotEmpty())
                {{-- El ajuste vive FUERA de la tabla a propósito. Dentro quedaba en la
                     última columna, o sea detrás del desplazamiento horizontal en un
                     teléfono, y el propio formulario era más ancho que la pantalla. --}}
                <details class="rounded-lg border border-gray-200 bg-white">
                    <summary class="flex min-h-11 cursor-pointer items-center px-4 text-sm font-semibold text-gray-800">Ajustar la deuda (nota de crédito, débito o corrección)</summary>
                    <form method="POST" action="{{ route('gastos.ajustes.store', $gasto->cuotas->first()) }}" class="space-y-3 border-t border-gray-200 p-4"
                          x-data="{ cuota: '{{ $gasto->cuotas->first()->id }}' }"
                          @submit="$el.action = '{{ url('gastos/cuotas') }}/' + cuota + '/ajustes'">
                        @csrf
                        <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <p class="text-xs text-gray-500">Un ajuste NO es un pago: cambia lo que se debe sin que salga dinero. Queda en el historial y se revierte, nunca se borra.</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="ajuste-cuota" class="block text-sm font-medium text-gray-700">Cuota *</label>
                                <select id="ajuste-cuota" x-model="cuota" class="{{ $control }}">
                                    @foreach ($gasto->cuotas as $c)
                                        <option value="{{ $c->id }}">Cuota {{ $c->numero }} · {{ $c->vence?->format('d/m/Y') ?? 'sin fecha' }} · saldo {{ Dinero::mostrar(max($saldos->pendienteCuota($c), 0)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ajuste-tipo" class="block text-sm font-medium text-gray-700">Tipo *</label>
                                <select id="ajuste-tipo" name="tipo" class="{{ $control }}">
                                    @foreach (Ajuste::TIPOS as $valor => $texto)<option value="{{ $valor }}">{{ $texto }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ajuste-direccion" class="block text-sm font-medium text-gray-700">Dirección *</label>
                                <select id="ajuste-direccion" name="direccion" class="{{ $control }}">
                                    <option value="credito">Crédito (baja la deuda)</option>
                                    <option value="debito">Débito (sube la deuda)</option>
                                </select>
                            </div>
                            <div>
                                <label for="ajuste-importe" class="block text-sm font-medium text-gray-700">Importe *</label>
                                <input id="ajuste-importe" name="importe" inputmode="decimal" required class="{{ $control }} tabular-nums" placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Un crédito no puede pasar del saldo de esa cuota.</p>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="ajuste-motivo" class="block text-sm font-medium text-gray-700">Motivo *</label>
                                <input id="ajuste-motivo" name="motivo" required minlength="5" maxlength="500" class="{{ $control }}" placeholder="Nota de crédito 123 por devolución">
                            </div>
                            @if ($creditosDisponibles->isNotEmpty())
                                {{-- Respaldar el crédito con la NC de Compras no es decorativo: es lo
                                     que permite controlar que no se descuente dos veces. El importe
                                     aplicado se acumula contra el total del documento, así que
                                     partirla en dos ajustes tampoco la duplica. --}}
                                <div class="sm:col-span-2">
                                    <label for="ajuste-documento" class="block text-sm font-medium text-gray-700">Respaldar con una nota de crédito de Compras (opcional)</label>
                                    <select id="ajuste-documento" name="documento_recibido_id" class="{{ $control }}">
                                        <option value="">Sin documento de respaldo</option>
                                        @foreach ($creditosDisponibles as $c)
                                            <option value="{{ $c->documento->id }}">
                                                {{ $c->documento->numero_control ?: $c->documento->codigo_generacion }}
                                                · {{ $c->documento->emisor_nombre }}
                                                · quedan {{ Dinero::mostrar($c->disponible) }} de {{ Dinero::mostrar($c->total) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">El crédito aplicado se descuenta del remanente del documento; lo que sobre queda identificado como pendiente de aplicar.</p>
                                </div>
                            @endif
                        </div>
                        <button type="submit" class="min-h-11 w-full rounded-md bg-gray-800 px-4 text-sm font-medium text-white sm:w-auto">Registrar ajuste</button>
                    </form>
                </details>
            @endif

            {{-- Pagos aplicados. La reversión es la única forma de corregir uno. --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Pagos aplicados</h2>

                @if ($pagos->isEmpty() && $aplicadoReservado->isEmpty())
                    <p class="px-4 py-3 text-sm text-gray-500">Todavía no se registró ningún pago sobre este gasto.</p>
                @endif

                <ul class="divide-y divide-gray-100">
                    @foreach ($pagos as $pago)
                        <li class="px-4 py-3 {{ $pago->vigente() ? '' : 'bg-gray-50' }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">
                                        <a href="{{ route('gastos.pagos.show', $pago) }}" class="text-indigo-700 underline">Pago #{{ $pago->id }}</a>
                                        · {{ $gasto->moneda }} {{ Dinero::mostrar($pago->importe) }}
                                        <span class="font-normal text-gray-600">· {{ $pago->etiquetaMetodo() }} · {{ $pago->fecha->format('d/m/Y') }}</span>
                                        @unless ($pago->vigente())
                                            <span class="ml-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Revertido</span>
                                        @endunless
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600">
                                        Aplicado a
                                        {{ $aplicaciones->get($pago->id, collect())
                                            ->map(fn ($a) => 'cuota '.($gasto->cuotas->firstWhere('id', $a->cuota_id)?->numero ?? '?').' ('.$gasto->moneda.' '.$a->importe.')')
                                            ->join(', ', ' y ') }}.
                                    </p>
                                    @if ($pago->referencia)<p class="text-sm text-gray-600">Referencia: {{ $pago->referencia }}</p>@endif
                                    <p class="text-xs text-gray-500">Pagó {{ $pago->pagador?->name }} · registró {{ $pago->registrador?->name }}</p>
                                    @unless ($pago->vigente())
                                        <p class="mt-1 text-sm text-red-700">Revertido por {{ $pago->reversor?->name }} el {{ $pago->revertido_at->format('d/m/Y H:i') }} — {{ $pago->motivo_reversion }}</p>
                                    @endunless
                                </div>
                            </div>

                            {{-- Comprobante: falta o no falta, y por qué. Son dos cosas
                                 distintas y se dicen por separado. --}}
                            @php $suyos = $comprobantes->get($pago->id, collect()); @endphp
                            <div class="mt-2">
                                @if ($suyos->isNotEmpty())
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Comprobantes del pago</p>
                                    <ul class="mt-1 flex flex-wrap gap-x-4">
                                        @foreach ($suyos as $adjunto)
                                            <li><a href="{{ route('gastos.archivo', ['adjunto' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener" class="inline-block min-h-11 break-all py-2 text-sm text-indigo-700 underline sm:min-h-0 sm:py-0">{{ $adjunto->nombre }}</a></li>
                                        @endforeach
                                    </ul>
                                @elseif ($pago->sin_comprobante)
                                    <p class="text-sm text-amber-700">
                                        <span class="font-medium">Falta comprobante.</span>
                                        Motivo declarado: «{{ $pago->sin_comprobante }}»
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">Sin comprobante y sin motivo declarado.</p>
                                @endif

                                @if ($puedePagar && $pago->vigente())
                                    <details class="mt-1">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-xs text-indigo-700 underline">Adjuntar comprobante</summary>
                                        <form method="POST" action="{{ route('gastos.pagos.comprobantes', $pago) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                                            @csrf
                                            <x-gastos-adjuntos nombre="comprobantes" titulo="Comprobantes del pago" />
                                            <button type="submit" class="min-h-11 rounded-md bg-gray-800 px-4 text-sm font-medium text-white">Adjuntar</button>
                                        </form>
                                    </details>
                                @endif

                                @if ($puedeCorregir && $pago->vigente())
                                    <details class="mt-1">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-xs text-red-700 underline">Revertir este pago</summary>
                                        <form method="POST" action="{{ route('gastos.pagos.revertir', $pago) }}" class="mt-2 space-y-2 rounded-md border border-red-200 bg-red-50 p-3">
                                            @csrf
                                            <p class="text-xs text-red-800">
                                                Revertir devuelve el saldo a la deuda y deja el registro en el historial. No borra nada, y no significa que el banco haya devuelto el dinero.
                                            </p>
                                            <label class="block text-xs font-medium text-gray-700" for="motivo-pago-{{ $pago->id }}">Motivo</label>
                                            <input id="motivo-pago-{{ $pago->id }}" name="motivo" required minlength="5" maxlength="500" class="{{ $control }}" placeholder="Se registró con la fecha equivocada">
                                            <button type="submit" class="min-h-11 rounded-md bg-red-700 px-4 text-sm font-medium text-white">Revertir</button>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        </li>
                    @endforeach

                    {{-- Pago mixto fuera de alcance: se muestra SOLO lo que tocó a este
                         gasto. Sin total, sin referencia, sin comprobante. --}}
                    @if ($aplicadoReservado->isNotEmpty())
                        @php $subtotal = $aplicadoReservado->sum(fn ($a) => Dinero::centavos((string) $a->importe)); @endphp
                        <li class="px-4 py-3">
                            <p class="text-sm font-semibold text-gray-800">Aplicado a este gasto: {{ $gasto->moneda }} {{ Dinero::mostrar($subtotal) }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $aplicadoReservado->map(fn ($a) => 'cuota '.$a->numero.' ('.$gasto->moneda.' '.$a->importe.')')->join(', ', ' y ') }}.</p>
                            <p class="mt-1 text-xs text-gray-500">El pago que lo cubre incluye obligaciones fuera de tu alcance, así que su detalle y su comprobante no se muestran acá.</p>
                        </li>
                    @endif
                </ul>
            </section>

            @if ($ajustes->isNotEmpty())
                <section class="rounded-lg border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Ajustes de deuda</h2>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($ajustes as $ajuste)
                            <li class="px-4 py-3 {{ $ajuste->vigente() ? '' : 'bg-gray-50' }}">
                                <p class="text-sm text-gray-800">
                                    <span class="font-semibold">{{ $ajuste->etiquetaTipo() }}</span>
                                    · {{ $ajuste->direccion === 'credito' ? '−' : '+' }}{{ $gasto->moneda }} {{ Dinero::mostrar($ajuste->importe) }}
                                    · cuota {{ $gasto->cuotas->firstWhere('id', $ajuste->cuota_id)?->numero }}
                                    @unless ($ajuste->vigente())<span class="ml-1 rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">Revertido</span>@endunless
                                </p>
                                <p class="text-sm text-gray-600">{{ $ajuste->motivo }}</p>
                                @if ($puedeAjustar && $ajuste->vigente())
                                    <details class="mt-1">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-xs text-red-700 underline">Revertir</summary>
                                        <form method="POST" action="{{ route('gastos.ajustes.revertir', $ajuste) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <input name="motivo" required minlength="5" maxlength="500" class="{{ $control }}" placeholder="Motivo de la reversión" aria-label="Motivo de la reversión">
                                            <button type="submit" class="min-h-11 rounded-md bg-red-700 px-4 text-sm font-medium text-white">Revertir ajuste</button>
                                        </form>
                                    </details>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Documento de cobro y comprobante de pago son respaldos DISTINTOS. --}}
            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-gray-800">Documentos del gasto</h2>
                <p class="mt-0.5 text-xs text-gray-500">Lo que te entregaron para cobrar. No es lo mismo que el comprobante del pago.</p>

                @if ($documentos->isNotEmpty())
                    <ul class="mt-2 space-y-1">
                        @foreach ($documentos as $adjunto)
                            <li class="text-sm">
                                <a href="{{ route('gastos.archivo', ['adjunto' => $adjunto->id, 'ver' => 1]) }}" target="_blank" rel="noopener" class="inline-block min-h-11 break-all py-2 text-indigo-700 underline sm:min-h-0 sm:py-0">{{ $adjunto->nombre }}</a>
                                <a href="{{ route('gastos.archivo', $adjunto->id) }}" class="ml-3 text-xs text-gray-500 underline">Descargar</a>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($gasto->documentacion === 'no_entregaron')
                    <p class="mt-2 text-sm text-gray-600">No entregaron documento. No se reclamará uno.</p>
                @else
                    <p class="mt-2 text-sm text-amber-700">Falta adjuntar el documento del gasto.</p>
                @endif

                @if ($gasto->fuentes->isNotEmpty())
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Vinculado con Compras</p>
                    <ul class="mt-1 space-y-1 text-sm text-gray-700">
                        @foreach ($gasto->fuentes as $fuente)
                            <li>
                                {{ $fuente->papel === 'deuda' ? 'Originó este gasto' : 'Respaldo' }}:
                                {{ $fuente->snapshot['numero_control'] ?? $fuente->snapshot['codigo_generacion'] ?? 'documento #'.$fuente->documento_recibido_id }}
                                @if ($fuente->snapshot['total'] ?? null)<span class="text-gray-500">· total {{ $fuente->snapshot['total'] }}</span>@endif
                                @can('documentos-recibidos.ver')
                                    <a href="{{ route('documentos-recibidos.index', ['buscar' => $fuente->snapshot['numero_control'] ?? '']) }}" class="ml-1 text-xs text-indigo-700 underline">Ver en Compras</a>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif

                @can('gastos.registrar')
                    <details class="mt-3">
                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-sm text-indigo-700 underline">Adjuntar documento</summary>
                        <form method="POST" action="{{ route('gastos.documentos', $gasto) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                            @csrf
                            <x-gastos-adjuntos nombre="documentos" titulo="Documentos del gasto" />
                            <button type="submit" class="min-h-11 rounded-md bg-gray-800 px-4 text-sm font-medium text-white">Adjuntar</button>
                        </form>
                    </details>
                @endcan
            </section>

            <section class="rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800">Historial</h2>
                <ol class="divide-y divide-gray-100">
                    @foreach ($historial as $evento)
                        <li class="flex flex-wrap items-baseline gap-x-2 px-4 py-2 text-sm">
                            <span class="tabular-nums text-gray-500">{{ \Illuminate\Support\Carbon::parse($evento->created_at)->format('d/m/Y H:i') }}</span>
                            <span class="font-medium text-gray-800">{{ $acciones[$evento->accion] ?? $evento->accion }}</span>
                            <span class="text-gray-500">· {{ $evento->usuario }}</span>
                            @php $datos = json_decode($evento->datos ?? '{}', true) ?: []; @endphp
                            @if (! empty($datos['motivo']))<span class="w-full text-gray-600">{{ $datos['motivo'] }}</span>@endif
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- «Este gasto se repite», desde un gasto que ya existe.

                 Lo importante está dicho en la pantalla, no solo en el código: el
                 gasto de ahora se queda como está —con sus pagos— y no se duplica su
                 período. Quien apriete el botón tiene que saber exactamente qué va a
                 pasar y qué NO va a pasar. --}}
            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">¿Este gasto se repite?</h2>

                @if ($reglaDeEsteGasto)
                    <p class="mt-2 text-sm text-gray-700">
                        Sí: viene de
                        <a href="{{ route('gastos.reglas.show', $reglaDeEsteGasto) }}" class="font-medium text-indigo-600 underline">{{ $reglaDeEsteGasto->nombre }}</a>.
                    </p>
                @elseif ($motivoNoRepetible)
                    <p class="mt-2 text-sm text-gray-600">{{ $motivoNoRepetible }}</p>
                @elseif (! $puedeRepetir)
                    <p class="mt-2 text-sm text-gray-600">No tenés permiso para configurar gastos que se repiten.</p>
                @else
                    <form method="POST" action="{{ route('gastos.repetir', $gasto) }}" class="mt-3 space-y-3"
                          x-data="{ abierto: {{ $errors->any() ? 'true' : 'false' }} }">
                        @csrf

                        <button type="button" x-show="! abierto" @click="abierto = true"
                                class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Configurar la repetición
                        </button>

                        <div x-show="abierto" x-cloak class="space-y-3">
                            <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                Este gasto <strong>se queda tal como está</strong>, con sus pagos, y cuenta como el período
                                que le toca: no se va a duplicar. Tampoco se crean los meses anteriores.
                                Solo se crearán los que vengan.
                            </p>

                            @include('gastos.partials.repeticion', [
                                'prefijo' => 'repeticion',
                                'diaPropuesto' => $diaPropuesto,
                                'mesPropuesto' => $mesPropuesto,
                                'modoPropuesto' => $gasto->montoDesconocido() ? 'variable' : 'fijo',
                            ])

                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                    Guardar la repetición
                                </button>
                                <button type="button" @click="abierto = false" class="inline-flex min-h-11 items-center px-3 text-sm text-gray-600 underline">Cancelar</button>
                            </div>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
