@php
    use App\Models\Planilla\Planilla;
    use App\Models\Planilla\PlanillaDocumento;
    use App\Services\Gastos\Dinero;
    use App\Services\Planilla\EstadoPlanilla;

    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    $insignia = [
        'preparado' => 'bg-gray-100 text-gray-700',
        'pendiente' => 'bg-amber-50 text-amber-800',
        'parcial' => 'bg-sky-50 text-sky-800',
        'pagado' => 'bg-emerald-50 text-emerald-800',
        'sin_obligacion' => 'bg-gray-100 text-gray-500',
    ];
    $d = $totales->enDecimal($resumen);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ Planilla::TIPOS_PERIODO[$planilla->tipo_periodo] }} · {{ $planilla->periodo }}
                </h1>
                <p class="text-sm text-gray-500">{{ $planilla->periodoEnPalabras() }} · {{ Planilla::ESTADOS[$planilla->estado] }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($planilla->borrador())
                    @can('planilla.gestionar')
                        <a href="{{ route('planilla.preparar', $planilla) }}" class="{{ $boton }}">Editar</a>
                    @endcan
                @endif
                <a href="{{ route('planilla.impresos', $planilla) }}" class="{{ $boton }}">Impresos</a>
                @if ($planilla->confirmada())
                    @can('planilla.pagar')
                        <a href="{{ route('planilla.pagos', $planilla) }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Pagos</a>
                    @endcan
                @endif
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-3">

            @if (session('planilla.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($planilla->estado === 'anulada')
                <div class="rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800">
                    <strong>Anulada</strong> el {{ $planilla->anulada_at?->format('d/m/Y H:i') }}: {{ $planilla->motivo_anulacion }}
                    <span class="block text-xs">Las obligaciones se extinguieron con un ajuste interno; nada se borró y no se emitió ningún documento fiscal.</span>
                </div>
            @endif

            {{-- ══ La misma resta de siempre, más el avance del pago ══ --}}
            <div class="grid gap-3 lg:grid-cols-2">
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Resumen · {{ $planilla->moneda }}</h2>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-gray-700">Total de ingresos</dt><dd class="whitespace-nowrap font-semibold tabular-nums">{{ $d['total_ingresos'] }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-700">− Descuentos</dt><dd class="whitespace-nowrap font-semibold tabular-nums">{{ $d['descuentos'] }}</dd></div>
                        <div class="flex justify-between gap-4 pl-4 text-xs text-gray-500"><dt>Se entregan a terceros</dt><dd class="tabular-nums">{{ $d['a_terceros'] }}</dd></div>
                        <div class="flex justify-between gap-4 pl-4 text-xs text-gray-500"><dt>Anticipos ya pagados</dt><dd class="tabular-nums">{{ $d['anticipos'] }}</dd></div>
                        <div class="flex justify-between gap-4 pl-4 text-xs text-gray-500"><dt>Otros descuentos</dt><dd class="tabular-nums">{{ $d['otros_descuentos'] }}</dd></div>
                        <div class="flex justify-between gap-4 border-t-2 border-gray-300 pt-2"><dt class="font-semibold">= A pagar a los empleados</dt><dd class="whitespace-nowrap text-lg font-bold tabular-nums text-indigo-900">{{ $d['a_pagar'] }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Avance del pago</h2>
                    @if ($planilla->borrador())
                        <p class="mt-2 text-sm text-gray-600">
                            Todavía no hay nada que pagar: un borrador no crea obligaciones.
                        </p>
                    @else
                        <dl class="mt-2 space-y-1 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-gray-700">A empleados</dt><dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar($avance['empleados_a_pagar']) }}</dd></div>
                            <div class="flex justify-between gap-4 pl-4 text-xs text-emerald-700"><dt>pagado</dt><dd class="tabular-nums">{{ Dinero::mostrar($avance['empleados_pagado']) }}</dd></div>
                            <div class="flex justify-between gap-4 pl-4 text-xs text-amber-700"><dt>pendiente</dt><dd class="tabular-nums">{{ Dinero::mostrar($avance['empleados_pendiente']) }}</dd></div>
                            <div class="flex justify-between gap-4 border-t border-gray-100 pt-1"><dt class="text-gray-700">A terceros</dt><dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar($avance['terceros_a_pagar']) }}</dd></div>
                            <div class="flex justify-between gap-4 pl-4 text-xs text-emerald-700"><dt>pagado</dt><dd class="tabular-nums">{{ Dinero::mostrar($avance['terceros_pagado']) }}</dd></div>
                            <div class="flex justify-between gap-4 pl-4 text-xs text-amber-700"><dt>pendiente</dt><dd class="tabular-nums">{{ Dinero::mostrar($avance['terceros_pendiente']) }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-gray-500">
                            {{ $avance['personas_pagadas'] }} de {{ $avance['personas'] }} personas cobradas.
                            Lo de terceros <strong>no se suma</strong> a lo de empleados: son dos partes del mismo total de ingresos.
                        </p>
                    @endif
                </section>
            </div>

            {{-- ══ Dinero desembolsado ══
                 Panel aparte del «avance del pago», y no una fila más dentro de él,
                 porque responde OTRA pregunta. El avance dice cuánto falta de las
                 obligaciones que esta planilla creó. Esto dice cuánto le costó el
                 período al negocio, y para eso hay que contar los anticipos: ese dinero
                 salió antes, por su propio pago, pero salió por esta gente y este
                 período. --}}
            @if (! $planilla->borrador() && $desembolsos)
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                        Dinero desembolsado · {{ $planilla->moneda }}
                    </h2>

                    <div class="mt-2 grid gap-4 lg:grid-cols-2">
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-700">Pagado a los empleados</dt>
                                <dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar($desembolsos['a_empleados']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-700">+ Anticipos entregados antes</dt>
                                <dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar($desembolsos['anticipos']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-700">+ Pagado a terceros</dt>
                                <dd class="whitespace-nowrap tabular-nums">{{ Dinero::mostrar($desembolsos['a_terceros']) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 border-t-2 border-gray-300 pt-2">
                                <dt class="font-semibold">= Total desembolsado</dt>
                                <dd class="whitespace-nowrap text-lg font-bold tabular-nums text-indigo-900">{{ Dinero::mostrar($desembolsos['total']) }}</dd>
                            </div>
                            @if ($desembolsos['pendiente'] > 0)
                                <div class="flex justify-between gap-4 text-xs text-amber-700">
                                    <dt>Todavía sin salir</dt>
                                    <dd class="tabular-nums">{{ Dinero::mostrar($desembolsos['pendiente']) }}</dd>
                                </div>
                            @endif
                        </dl>

                        <div class="rounded border border-gray-100 bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
                            <p>
                                Los <strong>anticipos</strong> se cuentan acá aunque su pago sea anterior y viva en su
                                propio gasto: para saber cuánto costó este período, ese dinero salió por este período.
                            </p>
                            <p class="mt-2">
                                No sumés esta cifra con el <strong>total de ingresos</strong> del resumen: el total de
                                ingresos <em>ya contiene</em> los anticipos. Son la misma plata mirada desde dos lados,
                                no dos plata distintas.
                            </p>
                            @if ($cuadre && $cuadre['cuadra'])
                                <p class="mt-2 font-medium text-emerald-700">
                                    Cuadra: {{ Dinero::mostrar($cuadre['desembolsado']) }} desembolsados
                                    @if ($desembolsos['pendiente'] > 0) + {{ Dinero::mostrar($desembolsos['pendiente']) }} por salir @endif
                                    @if ($cuadre['retenido'] > 0) + {{ Dinero::mostrar($cuadre['retenido']) }} retenidos @endif
                                    = {{ Dinero::mostrar($cuadre['total_ingresos']) }} de ingresos.
                                </p>
                            @elseif ($cuadre)
                                <p class="mt-2 font-medium text-rose-700">
                                    No cuadra por {{ Dinero::mostrar(abs($cuadre['diferencia'])) }}. Revisá los
                                    descuentos sin clasificar antes de fiarte de estas cifras.
                                </p>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            {{-- ══ Confirmar ══ --}}
            @if ($planilla->borrador())
                @can('planilla.gestionar')
                    <section class="rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                        <h2 class="text-sm font-semibold text-indigo-900">Confirmar la planilla</h2>
                        <p class="mt-1 text-sm text-indigo-900">
                            Al confirmar se crean las obligaciones: una con cada persona por lo que se le paga, y una
                            con cada tercero por lo que se le entrega. <strong>Se crean una sola vez</strong>, y a
                            partir de ahí la planilla deja de editarse.
                        </p>

                        @if ($reparos !== [])
                            <div class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                <p class="font-medium">Antes hay que resolver:</p>
                                <ul class="mt-1 list-inside list-disc">@foreach ($reparos as $r)<li>{{ $r }}</li>@endforeach</ul>
                            </div>
                        @else
                            <form method="POST" action="{{ route('planilla.confirmar', $planilla) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                                    Confirmar y crear las obligaciones
                                </button>
                            </form>
                        @endif
                    </section>
                @endcan
            @endif

            {{-- ══ Personas ══ --}}
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <h2 class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Personas</h2>
                <ul class="divide-y divide-gray-100">
                    @foreach ($planilla->detalles as $detalle)
                        @php $e = $estado->deDetalle($detalle); @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $detalle->nombre_snapshot }}</p>
                                <p class="text-xs text-gray-500">{{ $detalle->cargo_snapshot ?: 'Sin cargo' }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="whitespace-nowrap tabular-nums text-gray-900">{{ $planilla->moneda }} {{ Dinero::mostrar($detalle->a_pagar) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $insignia[$e['estado']] }}">{{ EstadoPlanilla::ETIQUETAS[$e['estado']] }}</span>
                                @if ($e['pendiente'] > 0 && ! $planilla->borrador())
                                    <span class="whitespace-nowrap text-xs text-amber-700">queda {{ Dinero::mostrar($e['pendiente']) }}</span>
                                @endif
                                @if ($detalle->gasto)
                                    <a href="{{ route('gastos.show', $detalle->gasto) }}" class="text-xs text-indigo-600 underline">Ver obligación</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- ══ Terceros ══ --}}
            @if ($terceros->isNotEmpty())
                <section class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <h2 class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold uppercase tracking-wide text-gray-500">
                        Terceros · lo que se les entrega
                    </h2>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($terceros as $t)
                            @php $e = $estado->deTercero($t); @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                <p class="text-sm font-medium text-gray-900">{{ $t->tercero }}</p>
                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                    <span class="whitespace-nowrap tabular-nums">{{ $planilla->moneda }} {{ Dinero::mostrar($t->importe) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $insignia[$e['estado']] }}">{{ EstadoPlanilla::ETIQUETAS[$e['estado']] }}</span>
                                    @if ($t->gasto)
                                        <a href="{{ route('gastos.show', $t->gasto) }}" class="text-xs text-indigo-600 underline">Ver obligación</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
                        Una obligación por tercero, sumando lo que se le descontó a todos. Solo existen desde que la
                        planilla se confirmó.
                    </p>
                </section>
            @endif

            {{-- ══ Documentos firmados ══ --}}
            @can('planilla.documentos')
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Documentos firmados</h2>

                    @if ($documentos->isNotEmpty())
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach ($documentos as $doc)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>
                                        <span class="font-medium text-gray-900">{{ PlanillaDocumento::TIPOS[$doc->tipo] }}</span>
                                        @if ($doc->detalle)
                                            <span class="text-gray-600">· {{ $doc->detalle->nombre_snapshot }}</span>
                                        @endif
                                        <span class="block text-xs text-gray-500">{{ $doc->nombre }} · {{ $doc->created_at?->format('d/m/Y H:i') }}</span>
                                    </span>
                                    <a href="{{ route('planilla.documentos.ver', $doc) }}" class="{{ $boton }}">Descargar</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <form method="POST" action="{{ route('planilla.documentos.store', $planilla) }}" enctype="multipart/form-data" class="mt-3 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <div>
                            <label for="tipo" class="block text-xs text-gray-500">Qué es</label>
                            <select id="tipo" name="tipo" class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                @foreach (PlanillaDocumento::TIPOS as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="planilla_detalle_id" class="block text-xs text-gray-500">¿De quién? (si es un recibo)</label>
                            <select id="planilla_detalle_id" name="planilla_detalle_id" class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="">Toda la planilla</option>
                                @foreach ($planilla->detalles as $detalle)
                                    <option value="{{ $detalle->id }}">{{ $detalle->nombre_snapshot }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="documento" class="block text-xs text-gray-500">Archivo</label>
                            <input id="documento" name="documento" type="file" required class="block w-full text-sm">
                            <button type="submit" class="{{ $boton }} mt-2 w-full">Adjuntar</button>
                        </div>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">
                        Se guardan en disco privado y solo se entregan por esta pantalla: un recibo de sueldo no puede
                        quedar detrás de una dirección adivinable.
                    </p>
                </section>
            @endcan

            {{-- ══ Anular ══ --}}
            @if ($planilla->confirmada())
                @can('planilla.gestionar')
                    <section class="rounded-lg border border-red-200 bg-white p-4">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Anular la planilla</h2>
                        <p class="mt-1 text-xs text-gray-600">
                            Extingue las obligaciones con un ajuste interno por lo que queda —queda el rastro, no se emite
                            ninguna nota de crédito— y libera los anticipos
                            que esta planilla iba a recuperar. <strong>No borra nada.</strong> Si ya hay pagos
                            registrados, primero hay que revertirlos uno por uno.
                        </p>
                        <form method="POST" action="{{ route('planilla.anular', $planilla) }}" class="mt-2 flex flex-wrap items-end gap-2">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <label for="motivo-anular" class="block text-xs text-gray-500">Motivo</label>
                                <input id="motivo-anular" name="motivo" type="text" required minlength="5" maxlength="500"
                                       class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-md border border-red-300 bg-white px-4 text-sm font-medium text-red-700 hover:bg-red-50">Anular</button>
                        </form>
                    </section>
                @endcan
            @endif
        </div>
    </div>
</x-app-layout>
