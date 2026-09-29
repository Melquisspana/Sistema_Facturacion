<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Lote PPQ #{{ $lote->id }} — {{ $lote->referencia }}</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('ppq.lotes.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Historial</a>
                @if ($lote->esEditable() && auth()->user()->can('ppq.gestionar'))
                    <a href="{{ route('ppq.index', ['lote' => $lote->id]) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Buscar / agregar CCF</a>
                @else
                    <a href="{{ route('ppq.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Buscar CCF</a>
                @endif
                @if ($resumen['cantidad'] > 0)
                    {{-- Paso 1 en el portal: el archivo de NC. Paso 2: el de quedan. --}}
                    @can('ppq.gestionar')
                        <a href="{{ route('ppq.lotes.archivo-nc', $lote) }}" class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            1 · Archivo de NC
                        </a>
                    @endcan
                    <a href="{{ route('ppq.lotes.quedan', $lote) }}" class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-3 py-2 text-sm font-medium text-white hover:bg-teal-700">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 00-1.5 0v8.614L6.295 8.235a.75.75 0 10-1.09 1.03l4.25 4.5a.75.75 0 001.09 0l4.25-4.5a.75.75 0 00-1.09-1.03l-2.955 3.129V2.75z"/><path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z"/></svg>
                        2 · Archivo de quedan
                    </a>
                @endif
                @if ($lote->esEditable())
                    @can('ppq.gestionar')
                        <a href="{{ route('ppq.lotes.edit', $lote) }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Editar</a>
                    @endcan
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $badge = [
            'borrador' => 'bg-gray-100 text-gray-700', 'listo' => 'bg-blue-100 text-blue-700',
            'enviado' => 'bg-amber-100 text-amber-700', 'pagado' => 'bg-green-100 text-green-700',
            'observado' => 'bg-red-100 text-red-700',
        ];

        // Totales y conteos del lote COMPLETO (no de la página visible).
        $totalCcf = $resumen['total_dte'];
        $totalAlb = $resumen['total_albaran'];
        $difTotal = $resumen['diferencia_sin_explicar'];
        $difDevoluciones = $resumen['diferencia_devoluciones'];
        $sinAlb = $resumen['sin_albaran'];
        $conDif = $resumen['con_diferencia'];
        $sinMonto = $resumen['sin_monto'];
        $otraSala = $resumen['otra_sala'];
        $estadoLote = \App\Support\PpqConciliacion::estadoLote($sinAlb, $conDif, $sinMonto, $otraSala);
        $money = fn ($v) => ((float) $v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
        $difBox = match ($estadoLote['key']) {
            'cuadra' => 'bg-green-50 ring-green-200',
            'incompleto' => 'bg-amber-50 ring-amber-200',
            default => 'bg-red-50 ring-red-200',
        };
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            @if ($resumen['cantidad'] > 0)
                <p class="text-xs text-gray-500">
                    En el portal: primero el archivo de NC, después el de quedan (CCF y sus NC). Luego cargá aquí el reporte del caso.
                    El TXT de pago se carga en <a href="{{ route('cobros.index') }}" class="text-indigo-600 hover:underline">Cobros Calleja</a> y actualiza este PPQ solo.
                </p>
            @endif

            {{-- Resumen superior --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Documentos</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $resumen['cantidad'] }}</div>
                    @if ($sinAlb > 0)
                        <div class="mt-1 text-xs text-amber-600">{{ $sinAlb }} sin albarán</div>
                    @endif
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total CCF/NC <span class="text-gray-400">(neto)</span></div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $money($totalCcf) }}</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-4">
                    <div class="text-xs text-gray-500">Total albarán <span class="text-gray-400">(neto)</span></div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $money($totalAlb) }}</div>
                </div>
                <div class="shadow-sm ring-1 {{ $difBox }} rounded-xl p-4">
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                        Diferencia total
                        @if ($estadoLote['alerta'])
                            <span class="cursor-help {{ $estadoLote['clase'] }}" title="{{ $estadoLote['motivo'] }}">
                                <svg class="h-4 w-4 inline" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            </span>
                        @endif
                    </div>
                    <div class="mt-1 text-2xl font-bold {{ $estadoLote['clase'] }}">{{ $money($difTotal) }}</div>
                    <div class="mt-0.5 text-xs {{ $estadoLote['clase'] }}">{{ $estadoLote['alerta'] ? $estadoLote['motivo'] : 'Todo cuadra' }}</div>
                    @if ($difDevoluciones > 0)
                        <div class="mt-0.5 text-xs text-gray-500" title="Calleja descuenta la devolución del albarán de entrega (con su descuento); la NC AC04 va sin descuento.">
                            {{ $money($difDevoluciones) }} cubiertos por devoluciones AC04
                        </div>
                    @endif
                </div>
            </div>

            {{-- Metadatos del lote --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-5 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-xs text-gray-500">Estado</dt><dd class="mt-0.5"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge[$lote->estado->value] ?? 'bg-gray-100 text-gray-700' }}">{{ $lote->estado->label() }}</span></dd></div>
                <div><dt class="text-xs text-gray-500">Fecha</dt><dd class="mt-0.5 text-gray-700">{{ $lote->fecha->format('d/m/Y') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Cliente</dt><dd class="mt-0.5 text-gray-700">{{ $lote->cliente?->nombre ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Creado</dt><dd class="mt-0.5 text-gray-700">{{ $lote->created_at?->format('d/m/Y') ?? '—' }}</dd></div>
                @if ($lote->observaciones)
                    <div class="col-span-2 sm:col-span-4"><dt class="text-xs text-gray-500">Observaciones</dt><dd class="mt-0.5 text-gray-700">{{ $lote->observaciones }}</dd></div>
                @endif
            </div>

            {{-- Reporte del caso que devuelve el portal: deja el PPQ presentado. --}}
            @if ($resumen['cantidad'] > 0 && auth()->user()->can('ppq.gestionar'))
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-5">
                    <h3 class="text-sm font-semibold text-gray-700">Reporte del caso de Calleja</h3>
                    <p class="mt-1 text-xs text-gray-500">Después de reportar el caso en el portal, subí el Excel que devuelve. Lo que Calleja registró queda presentado; lo que no tomó vuelve a «por presentar» para el siguiente PPQ.</p>
                    <form method="POST" action="{{ route('ppq.lotes.reporte-caso', $lote) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
                        @csrf
                        <input type="file" name="reporte" accept=".xls,.xlsx" required
                               class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                        <div>
                            <label for="caso" class="block text-xs text-gray-500">N.º de caso (del correo)</label>
                            <input id="caso" name="caso" type="text" inputmode="numeric" class="mt-0.5 w-28 rounded-md border-gray-300 text-sm">
                        </div>
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Cargar reporte</button>
                    </form>
                    @error('reporte')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif

            {{-- Documentos del lote: CCF primero y, en cada grupo, los más recientes arriba --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-ink-600 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-700">Documentos del lote</h3>
                    <span class="text-xs text-gray-500">
                        @if ($items->isNotEmpty())
                            Mostrando {{ $items->firstItem() }}–{{ $items->lastItem() }} de {{ $items->total() }} ·
                        @endif
                        CCF más recientes primero, con su albarán; los totales son del lote completo
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">Documento</th>
                                <th class="py-2.5 px-3">Albarán</th>
                                <th class="py-2.5 px-3">Sala / CD</th>
                                <th class="py-2.5 px-3 text-right">Monto albarán</th>
                                <th class="py-2.5 px-3 text-right">Monto CCF/NC</th>
                                <th class="py-2.5 px-3 text-center">Estado</th>
                                <th class="py-2.5 px-3"><span class="sr-only">Acción</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($items as $item)
                                @php
                                    $tipo = $item->tipo_dte ?? $item->dte?->tipo_dte?->value;
                                    $control = $item->numero_control ?? $item->dte?->numero_control;
                                    $sello = $item->sello_recepcion ?? $item->dte?->sello_recepcion;
                                    $codigo = $item->codigo_generacion ?? $item->dte?->codigo_generacion;
                                    $numAlb = \App\Support\Albaran::numeroLimpio($item->albaran?->numero_albaran);
                                    $sala = $item->salaCodigo();
                                    $salaNombre = $item->salaNombre();
                                    $salaDescripcion = $item->salaDescripcion();
                                    $estado = $item->conciliacionEstado();
                                    $mismatch = $item->salaMismatch();
                                    $alerta = in_array($estado['key'], ['pequena', 'posible_nc'], true);
                                    // Con el mismo signo que los montos de la fila (NC en negativo).
                                    $difSigno = $item->diferenciaConSigno();
                                    // Una NC que difiere de su albarán de crédito no es una «posible
                                    // NC»: ya lo es. Misma alerta, texto propio; el CCF no cambia.
                                    if ($estado['key'] === 'posible_nc' && $item->esNc()) {
                                        $estado['label'] = 'Difiere del albarán de crédito';
                                    }
                                    // Diferencia cubierta por una NC de devolución (AC04) de la misma sala.
                                    if (in_array($item->id, $resumen['explicadas'], true)) {
                                        $estado = ['key' => 'coincide', 'label' => 'Cubierta por devolución AC04', 'clase' => 'bg-green-100 text-green-700'];
                                        $alerta = false;
                                    }
                                    $tip = match ($estado['key']) {
                                        'coincide' => 'El monto del albarán coincide con el del CCF/NC.',
                                        'pequena' => 'Diferencia pequeña entre el albarán y el CCF/NC ('.$money($difSigno).').',
                                        'posible_nc' => $item->esNc()
                                            ? 'El monto de la nota difiere del de su albarán de crédito ('.$money($difSigno).'): revisar descuento, retención o captura.'
                                            : 'El monto difiere ('.$money($difSigno).'): posible nota de crédito o devolución.',
                                        'albaran_sin_monto' => 'Hay un albarán vinculado pero sin monto capturado; capturá el monto para conciliar.',
                                        default => 'Documento sin albarán vinculado.',
                                    };
                                @endphp
                                @php
                                    $montoAlbSigno = $item->montoAlbaranConSigno();
                                    $montoDteSigno = $item->montoDteConSigno();
                                    $fmt = fn ($v) => ($v < 0 ? '−$' : '$').number_format(abs((float) $v), 2);
                                @endphp
                                <tr class="align-top hover:bg-gray-50 dark:hover:bg-ink-700/40">
                                    <td class="py-2 px-3" title="{{ $control }} · código {{ $codigo ?: '—' }} · sello {{ $sello ?: '—' }}">
                                        <span class="font-mono font-semibold {{ $item->esNc() ? 'text-rose-600' : 'text-gray-800' }}">{{ preg_match('/(\d+)$/', (string) $control, $mc) ? (ltrim($mc[1], '0') ?: '0') : ($control ?: '—') }}</span>
                                        <span class="text-[11px] {{ $item->esNc() ? 'text-rose-500 font-medium' : 'text-gray-400' }}">{{ $tipo === '05' ? 'NC' : 'CCF' }}</span>
                                        @if ($item->numero_orden_compra)
                                            <span class="block font-mono text-[11px] text-gray-400">OC {{ $item->numero_orden_compra }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 font-mono text-xs">
                                        @if ($item->sin_albaran && ! $item->esNc())
                                            <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500">sin albarán</span>
                                        @else
                                            {{ $numAlb ?: ($item->dte?->albaran?->numero_canonico ?? '—') }}
                                        @endif
                                        @if ($item->observaciones)
                                            <span class="cursor-help text-gray-400" title="{{ $item->observaciones }}">ⓘ</span>
                                        @endif
                                        <span class="block font-sans text-[11px] text-gray-400">{{ optional($item->albaran?->fecha_albaran)->format('d/m/Y') }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-gray-700">
                                        <span class="block {{ $salaNombre ? 'text-gray-800' : 'text-amber-600' }}">{{ $salaDescripcion }}</span>
                                        @if ($sala)
                                            <span class="block font-mono text-[11px] text-gray-400">{{ $sala }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-right whitespace-nowrap {{ $item->esNc() ? 'text-rose-600' : 'text-gray-700' }}">{{ $montoAlbSigno !== null ? $fmt($montoAlbSigno) : '—' }}</td>
                                    <td class="py-2 px-3 text-right whitespace-nowrap font-medium {{ $item->esNc() ? 'text-rose-600' : 'text-gray-800' }}">{{ $fmt($montoDteSigno) }}</td>
                                    <td class="py-2 px-3 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $estado['clase'] }}">
                                            @if ($alerta)
                                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                            @endif
                                            <span class="cursor-help" title="{{ $tip }}">{{ $estado['label'] }}</span>
                                        </span>
                                        @if ($difSigno !== null)
                                            <span class="mt-1 block text-[11px] {{ abs($difSigno) >= 0.01 ? 'text-red-600 font-medium' : 'text-gray-400' }}">Dif {{ $money($difSigno) }}</span>
                                        @endif
                                        @if ($mismatch)
                                            <span class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $mismatch['clase'] }} cursor-help" title="{{ $mismatch['detalle'] }}">
                                                ⚠ {{ $mismatch['label'] }} ({{ $mismatch['sala_albaran'] }})
                                            </span>
                                        @endif
                                        {{-- Estado de pago: solo "pagado" si el TXT de Calleja lo confirma --}}
                                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium {{ $item->estadoPagoClase() }}" @if ($item->fecha_pago) title="Fecha del documento en TXT: {{ \App\Support\Fecha::dmy($item->fecha_pago) }}" @endif>{{ $item->estadoPagoLabel() }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-right whitespace-nowrap">
                                        {{-- Una sola acción: si está cobrado, quitar el cobro; si no, quitarlo del lote. --}}
                                        @if ($item->estaConciliado())
                                            @can('ppq.revertir-conciliacion')
                                                <form method="POST" action="{{ route('ppq.lotes.items.revertir-cobro', [$lote, $item]) }}"
                                                      onsubmit="const m = prompt('Motivo (opcional):'); if (m === null) return false; this.motivo.value = m; return true;">
                                                    @csrf
                                                    <input type="hidden" name="page" value="{{ $items->currentPage() }}">
                                                    <input type="hidden" name="motivo" value="">
                                                    <button class="text-xs text-amber-700 hover:underline">quitar cobro</button>
                                                </form>
                                            @endcan
                                        @elseif ($lote->esEditable() && auth()->user()->can('ppq.gestionar'))
                                            <form method="POST" action="{{ route('ppq.lotes.items.destroy', [$lote, $item]) }}" onsubmit="return confirm('¿Quitar este documento del PPQ?')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-600 hover:underline">quitar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-10 text-center text-gray-500">
                                    @if ($resumen['cantidad'] > 0)
                                        {{-- Página fuera de rango: el lote SÍ tiene documentos. --}}
                                        Esta página no existe ({{ $resumen['cantidad'] }} documento(s) en {{ $items->lastPage() }} página(s)).
                                        <a href="{{ $items->url(1) }}" class="text-indigo-600 hover:underline">Ir a la primera página</a>.
                                    @else
                                        El lote no tiene documentos. <a href="{{ route('ppq.index', $lote->esEditable() ? ['lote' => $lote->id] : []) }}" class="text-indigo-600 hover:underline">Agregá CCF/NC desde la búsqueda</a>.
                                    @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                        @if ($resumen['cantidad'] > 0)
                            <tfoot>
                                <tr class="bg-gray-50 border-t-2 border-gray-200 font-semibold text-gray-800">
                                    <td class="py-2.5 px-3 text-xs uppercase text-gray-500" colspan="3">Totales del lote (neto)</td>
                                    <td class="py-2.5 px-3 text-right">{{ $money($totalAlb) }}</td>
                                    <td class="py-2.5 px-3 text-right">{{ $money($totalCcf) }}</td>
                                    <td class="py-2.5 px-3 text-center text-xs {{ $estadoLote['clase'] }}">Dif {{ $money($difTotal) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                @if ($items->hasPages())
                    <nav class="px-4 py-3 border-t border-gray-100 dark:border-ink-600" aria-label="Páginas de documentos del lote">{{ $items->links() }}</nav>
                @endif
            </div>

            {{-- Historial de conciliaciones. Cada archivo de pagos procesado y cada
                 corrección manual, con quién y con qué resultado. Antes esto no existía:
                 se conciliaba, se sobrescribía el estado y el archivo se descartaba, así
                 que no había forma de saber de dónde salía un pago ni quién lo había
                 quitado. Es una bitácora: solo se lee. --}}
            @if ($lote->conciliaciones->isNotEmpty())
                <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-700">Historial de conciliación ({{ $lote->conciliaciones->count() }})</h3>
                        <p class="mt-0.5 text-xs text-gray-500">Un archivo solo actualiza los documentos que nombra; los demás conservan su estado.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-600 bg-gray-50 border-b border-gray-200">
                                <th class="py-2.5 px-3">Cuándo</th>
                                <th class="py-2.5 px-3">Origen</th>
                                <th class="py-2.5 px-3">Archivo / motivo</th>
                                <th class="py-2.5 px-3">Quién</th>
                                <th class="py-2.5 px-3 text-right">Cambiados</th>
                                <th class="py-2.5 px-3 text-right">Sin cambio</th>
                            </tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($lote->conciliaciones as $corrida)
                                    <tr class="hover:bg-gray-50 align-top">
                                        <td class="py-2 px-3 text-gray-700 whitespace-nowrap">{{ $corrida->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                                        <td class="py-2 px-3">
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $corrida->origen->clase() }}">{{ $corrida->origen->label() }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-gray-700">
                                            @if ($corrida->archivo_nombre)
                                                <span class="font-mono text-xs">{{ $corrida->archivo_nombre }}</span>
                                                {{-- La huella es lo que hace verificable la copia guardada. --}}
                                                <span class="block font-mono text-[10px] text-gray-400" title="SHA-256 del archivo procesado">{{ substr((string) $corrida->archivo_hash, 0, 16) }}…</span>
                                            @endif
                                            @if ($corrida->motivo)
                                                <span class="block text-xs text-gray-600">{{ $corrida->motivo }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-gray-700">{{ $corrida->usuario?->name ?? '—' }}</td>
                                        <td class="py-2 px-3 text-right tabular-nums text-gray-800">{{ $corrida->items_cambiados }}</td>
                                        <td class="py-2 px-3 text-right tabular-nums text-gray-500">{{ $corrida->items_sin_cambio }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($lote->esEditable() && auth()->user()->can('ppq.gestionar'))
                <div class="flex justify-end">
                    <form method="POST" action="{{ route('ppq.lotes.destroy', $lote) }}" onsubmit="return confirm('¿Eliminar todo el lote?')">
                        @csrf @method('DELETE')
                        <button class="text-sm text-red-600 hover:underline">Eliminar lote</button>
                    </form>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
