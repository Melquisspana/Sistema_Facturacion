{{--
    Ficha de resultado de búsqueda CCF/NC para PPQ, en bloques visuales.
    Espera:
      $r            array normalizado del documento (lo arma la vista busqueda.blade)
      $lotesAbiertos colección de lotes editables a los que se puede agregar
--}}
@php
    $esNc = (bool) $r['esNc'];
    $montoNum = $r['monto'] !== null ? (float) $r['monto'] : null;
    $montoCcf = $montoNum === null ? '—' : ($esNc ? '−$'.number_format(abs($montoNum), 2) : '$'.number_format($montoNum, 2));

    // El albarán automático solo aplica al CCF; la NC lo captura a mano.
    $hayAlbaran = ! $esNc && $r['estado']['key'] !== 'sin_albaran';
    $montoAlb = $r['albaranMonto'] !== null ? '$'.number_format((float) $r['albaranMonto'], 2) : '—';
    $difTxt = $r['diferencia'] !== null ? '$'.number_format((float) $r['diferencia'], 2) : '—';
    $sala = $r['sala'] ? str_pad($r['sala'], 4, '0', STR_PAD_LEFT) : null;
    // Nombre comercial de la sala: vía la sucursal relacionada al CCF o por el código.
    $salaNombre = \App\Support\Sala::nombrePreferido($sala, $r['salaNombre'] ?? null);
    $salaDescripcion = \App\Support\Sala::descripcion($sala, $r['salaNombre'] ?? null);

    $difColor = match ($r['estado']['key']) {
        'coincide' => 'text-green-700',
        'pequena' => 'text-amber-700',
        'posible_nc' => 'text-red-700',
        default => 'text-gray-500',
    };
    $difBox = match ($r['estado']['key']) {
        'coincide' => 'bg-green-50 border-green-200',
        'pequena' => 'bg-amber-50 border-amber-200',
        'posible_nc' => 'bg-red-50 border-red-200',
        default => 'bg-gray-50 border-gray-200',
    };
    $tooltip = match ($r['estado']['key']) {
        'coincide' => 'El monto del albarán coincide con el del CCF/NC.',
        'pequena' => 'Diferencia pequeña entre el albarán y el CCF/NC; conviene revisarla.',
        'posible_nc' => 'El monto del albarán difiere del CCF/NC: posible nota de crédito o devolución.',
        default => 'No se encontró albarán para esta orden de compra.',
    };
    $alerta = in_array($r['estado']['key'], ['pequena', 'posible_nc'], true);

    // URL a la búsqueda manual de albarán por fecha, con el contexto del documento.
    $albaranFechaUrl = route('ppq.albaranes_por_fecha', array_filter([
        'origen' => $r['origen'],
        'dte_id' => $r['dteId'] ?? null,
        'numero_control' => $r['numeroControl'],
        'codigo_generacion' => $r['codigoGeneracion'],
        'sello_recepcion' => $r['sello'],
        'tipo_dte' => $r['tipoDte'],
        'fecha_documento' => $r['fecha'],
        'numero_orden_compra' => $r['ordenCompra'],
        'monto_dte' => $r['monto'],
        'gmail_message_id' => $r['gmailMessageId'] ?? null,
        'q' => request('q'),
        'fecha' => $r['fecha'],
    ], fn ($v) => $v !== null && $v !== ''));

    // Lote ACTIVO (se llegó desde un lote): se agrega DIRECTO a él, sin elegir de la lista.
    $loteFijo = $loteActivo ?? null;

    // Campos ocultos del documento, comunes a ambos botones/formularios.
    $hiddenDoc = $r['origen'] === 'gmail'
        ? [
            'origen' => 'gmail',
            'numero_control' => $r['numeroControl'],
            'codigo_generacion' => $r['codigoGeneracion'],
            'sello_recepcion' => $r['sello'],
            'tipo_dte' => $r['tipoDte'],
            'fecha_documento' => $r['fecha'],
            'numero_orden_compra' => $r['ordenCompra'],
            'monto_dte' => $r['monto'],
            'gmail_message_id' => $r['gmailMessageId'] ?? null,
            'sala_nombre' => $salaNombre, // nombre comercial ya resuelto (se snapshotea en el item)
        ]
        : array_filter(['dte_id' => $r['dteId'] ?? null], fn ($v) => $v !== null);
@endphp

<div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">

    {{-- BLOQUE 1 — Documento encontrado --}}
    <div class="p-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-block rounded-md {{ $esNc ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200' : 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' }} px-2.5 py-0.5 text-xs font-semibold">
                    {{ $esNc ? 'Nota de crédito' : 'CCF' }}
                </span>
                @if ($esNc)
                    <span class="inline-block rounded bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-600">resta del PPQ</span>
                @endif
                @if ($r['fuente'])
                    <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $r['fuente'] }}</span>
                @endif
                @if ($r['yaEn'])
                    <span class="inline-block rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">Ya está en el lote #{{ $r['yaEn'] }}</span>
                @endif
            </div>
            <div class="text-right">
                <div class="text-2xl font-bold tracking-tight {{ $esNc ? 'text-rose-700' : 'text-gray-900' }}">{{ $montoCcf }}</div>
                <div class="text-xs text-gray-400">{{ $esNc ? 'monto a restar' : 'monto del documento' }}</div>
            </div>
        </div>
        <dl class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 text-sm">
            <div><dt class="text-xs text-gray-500">N° de control</dt><dd class="font-mono text-xs break-all text-gray-700">{{ $r['numeroControl'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Código de generación</dt><dd class="font-mono text-xs break-all text-gray-700">{{ $r['codigoGeneracion'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Sello de recepción</dt><dd class="font-mono text-xs break-all text-gray-700">{{ $r['sello'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Fecha</dt><dd class="text-gray-700">{{ \App\Support\Fecha::dmy($r['fecha']) ?: '—' }}</dd></div>
        </dl>
    </div>

    {{-- BLOQUE 2 — Orden de compra y sala (badges) --}}
    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Orden de compra</span>
            <span class="inline-flex items-center rounded-md bg-white ring-1 ring-gray-200 px-2.5 py-1 font-mono text-xs text-gray-700">{{ $r['ordenCompra'] ?: '—' }}</span>
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-400 sm:ml-4">Sala / CD</span>
            <span class="inline-flex items-center rounded-md bg-indigo-50 ring-1 ring-indigo-200 px-3 py-1 text-sm font-bold text-indigo-700 font-mono">{{ $sala ?? '—' }}</span>
        </div>
        <div class="mt-2 flex flex-wrap items-baseline gap-x-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Nombre de sala</span>
            <span class="text-sm font-medium {{ $salaNombre ? 'text-gray-800' : 'text-amber-600' }}">{{ $salaDescripcion }}</span>
        </div>
        @if ($esNc && ! empty($r['ccfRelacionado']))
            <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-indigo-50 ring-1 ring-indigo-200 px-2.5 py-1 text-xs text-indigo-700">
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M8.75 3.75a.75.75 0 00-1.5 0v3.5h-3.5a.75.75 0 000 1.5h3.5v3.5a.75.75 0 001.5 0v-3.5h3.5a.75.75 0 000-1.5h-3.5v-3.5z"/></svg>
                Relación sugerida: misma OC que el CCF <span class="font-mono font-semibold">{{ $r['ccfRelacionado'] }}</span>
            </p>
        @endif
    </div>

    @if ($esNc)
        {{-- BLOQUE 3 (NC) — Albarán de captura MANUAL + agregar (resta) --}}
        <div class="px-6 py-5 border-t border-gray-100">
            <div class="flex items-center justify-between mb-1">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Albarán de la nota de crédito</h4>
                <span class="rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-rose-200">Captura manual</span>
            </div>
            <p class="text-xs text-gray-500 mb-3">El albarán de la NC no llega por correo (sale del proceso físico de entrega/avería). Completalo a mano; si todavía no lo tenés, podés dejarlo en blanco.</p>
            <a href="{{ $albaranFechaUrl }}" class="mb-4 inline-flex items-center gap-1.5 rounded-md bg-white ring-1 ring-indigo-200 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5a.25.25 0 00-.25.25v7.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-7.5a.25.25 0 00-.25-.25H4.75z" clip-rule="evenodd" /></svg>
                Buscar albarán por fecha
            </a>

            @if ($loteFijo === null && $lotesAbiertos->isEmpty())
                <a href="{{ route('ppq.lotes.create') }}" class="text-sm font-medium text-indigo-600 hover:underline">Crear un PPQ para agregar esta NC →</a>
            @else
                <form method="POST" action="" onsubmit="this.action='{{ url('ppq/lotes') }}/'+this.lote.value+'/items'">
                    @csrf
                    @foreach ($hiddenDoc as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="hidden" name="sin_albaran" value="0">

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">N° de albarán</label>
                            <input type="text" name="numero_albaran" placeholder="AC01/0236/00/6359" class="w-full rounded-md border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Fecha del albarán</label>
                            <input type="date" name="fecha_albaran" class="w-full rounded-md border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Monto del albarán</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400 text-sm">$</span>
                                <input type="number" step="0.01" min="0" name="monto_albaran" placeholder="0.00" class="w-full rounded-md border-gray-300 text-sm pl-6">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Observaciones</label>
                            <input type="text" name="observaciones" maxlength="500" placeholder="Avería, devolución…" class="w-full rounded-md border-gray-300 text-sm">
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4">
                        @if ($loteFijo)
                            <input type="hidden" name="lote" value="{{ $loteFijo->id }}">
                            <span class="mr-auto text-xs text-gray-500">Se agregará al lote <span class="font-semibold text-gray-700">#{{ $loteFijo->id }}</span></span>
                        @else
                            <label class="text-xs text-gray-500">Agregar a:</label>
                            <select name="lote" class="rounded-md border-gray-300 text-sm py-1">
                                @foreach ($lotesAbiertos as $l)
                                    <option value="{{ $l->id }}">#{{ $l->id }} · {{ Str::limit($l->referencia, 24) }}</option>
                                @endforeach
                            </select>
                        @endif
                        <button type="submit" class="rounded-md bg-rose-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-rose-700">
                            Agregar NC al PPQ (resta)
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @else
        {{-- BLOQUE 3 (CCF) — Albarán automático y conciliación --}}
        <div class="px-6 py-5 border-t border-gray-100">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Albarán encontrado</h4>
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $r['estado']['clase'] }}">
                    @if ($alerta)
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                    @endif
                    {{ $r['estado']['label'] }}
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-lg bg-gray-50 ring-1 ring-gray-200 px-4 py-3">
                    <div class="text-xs text-gray-500">Monto CCF</div>
                    <div class="mt-0.5 text-lg font-bold text-gray-900">{{ $montoCcf }}</div>
                </div>
                <div class="rounded-lg bg-gray-50 ring-1 ring-gray-200 px-4 py-3">
                    <div class="text-xs text-gray-500">Monto albarán</div>
                    <div class="mt-0.5 text-lg font-bold text-gray-900">{{ $montoAlb }}</div>
                </div>
                <div class="rounded-lg border {{ $difBox }} px-4 py-3">
                    <div class="flex items-center gap-1 text-xs text-gray-500">
                        Diferencia
                        @if ($alerta)
                            <span class="cursor-help {{ $difColor }}" title="{{ $tooltip }}">
                                <svg class="h-3.5 w-3.5 inline" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            </span>
                        @endif
                    </div>
                    <div class="mt-0.5 text-lg font-bold {{ $difColor }}">{{ $hayAlbaran ? $difTxt : '—' }}</div>
                </div>
            </div>

            @if ($hayAlbaran)
                <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500">N° de albarán</dt><dd class="font-mono text-sm font-semibold text-gray-800">{{ $r['albaranNumero'] ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Fecha del albarán</dt><dd class="text-gray-700">{{ \App\Support\Fecha::dmy($r['albaranFecha']) ?: '—' }}</dd></div>
                </dl>
            @else
                <p class="mt-4 text-sm text-gray-500">No se encontró albarán para esta orden de compra. Podés agregarlo igual con <span class="font-medium text-gray-700">“Agregar sin albarán”</span>.</p>
            @endif
        </div>

        {{-- Acciones CCF --}}
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-wrap items-center justify-end gap-2">
            @unless ($hayAlbaran)
                <a href="{{ $albaranFechaUrl }}" class="mr-auto inline-flex items-center gap-1.5 rounded-md bg-white ring-1 ring-indigo-200 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5a.25.25 0 00-.25.25v7.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-7.5a.25.25 0 00-.25-.25H4.75z" clip-rule="evenodd" /></svg>
                    Buscar albarán por fecha
                </a>
            @endunless
            @if ($loteFijo === null && $lotesAbiertos->isEmpty())
                <a href="{{ route('ppq.lotes.create') }}" class="text-sm font-medium text-indigo-600 hover:underline">Crear un PPQ para agregarlo →</a>
            @else
                <form method="POST" action="" class="flex flex-wrap items-center justify-end gap-2"
                      onsubmit="this.action='{{ url('ppq/lotes') }}/'+this.lote.value+'/items'">
                    @csrf
                    @foreach ($hiddenDoc as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="hidden" name="monto_albaran" value="{{ $r['albaranMonto'] }}">
                    <input type="hidden" name="numero_albaran" value="{{ $r['albaranNumero'] }}">
                    <input type="hidden" name="fecha_albaran" value="{{ $r['albaranFecha'] }}">
                    @if (! empty($r['albaranId']))
                        <input type="hidden" name="ppq_albaran_id" value="{{ $r['albaranId'] }}">
                    @endif

                    @if ($loteFijo)
                        <input type="hidden" name="lote" value="{{ $loteFijo->id }}">
                        <span class="mr-auto text-xs text-gray-500">Se agregará al lote <span class="font-semibold text-gray-700">#{{ $loteFijo->id }}</span></span>
                    @else
                        <label class="text-xs text-gray-500">Agregar a:</label>
                        <select name="lote" class="rounded-md border-gray-300 text-sm py-1">
                            @foreach ($lotesAbiertos as $l)
                                <option value="{{ $l->id }}">#{{ $l->id }} · {{ Str::limit($l->referencia, 24) }}</option>
                            @endforeach
                        </select>
                    @endif

                    @if ($hayAlbaran)
                        <button type="submit" name="sin_albaran" value="0"
                                class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                            Agregar con albarán
                        </button>
                    @endif
                    <button type="submit" name="sin_albaran" value="1"
                            class="rounded-md {{ $hayAlbaran ? 'bg-white ring-1 ring-gray-300 text-gray-700 hover:bg-gray-100' : 'bg-indigo-600 text-white hover:bg-indigo-700' }} px-4 py-1.5 text-sm font-medium">
                        Agregar sin albarán
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
